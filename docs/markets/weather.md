# Weather

Decision 12: OpenWeatherMap's free plan, which allows commercial use as long as **"Weather data © OpenWeather"**
is visible wherever the weather is shown. Every weather answer of the API carries it in `meta.attribution`.

## Fetching

* Key: `OPENWEATHER_API_KEY` in `.env` only. Empty = nothing is called, nothing counts as a failure, and the last
  stored weather stays.
* Two calls per city: `/data/2.5/weather` (now) and `/data/2.5/forecast` (5 days in 3-hour slots), `units=metric`,
  the city's coordinates. 12 cities × 2 calls × 24 hours ≈ 17,300 calls a month (the free plan allows far more).
* `weather:fetch --due` runs every minute; with n visible cities the i-th (by id) is due at minute ⌊i × 60 / n⌋,
  so each city is fetched once an hour and the calls are spread over the hour. `weather:fetch` (all) or
  `weather:fetch {slug}` by hand. The cities screen's buttons queue one job per city (`FetchCityWeather`) and return
  at once. A run stops after two cities in a row fail at the source (it is down); the others keep their last weather.
* Now: one `weather_observations` row per observation time (the same observation twice is kept once).
  Wind comes in m/s and is stored in km/h. Day or night comes from the source's icon (`01d` / `01n`).
* Forecast: replaces the city's rows. Slots are grouped by Palestine date; the low and high are over the day's slots,
  the picture is the slot nearest 13:00. A day needs four slots, except today, whose low and high gather over the day
  (late in the evening no slot of today is left, so today's row is kept). The API shows five days, today first.
* `cities:install` adds any of the twelve cities of 12.1 that is missing and never changes one the admin edited.

## The key travels in the address

OpenWeather takes the key as `appid=` in the query string; there is no header alternative. So:

* `OutboundHttp` turns every failure (connection, status, size) into a fixed Arabic sentence without the address.
* `ProviderHealth` scrubs every message again (`Secrets::scrub`: the configured keys and any `appid=`/`key=` value).
* Redirects are never followed, answers are capped at 256 KB.
* Tests make a failure whose exception message contains the full address with the key, three times (so an alert is
  raised), and check `provider_health`, the alerts, the log, failed jobs, the command output and every API answer.

## What is not believed

Temperatures outside −30…60 °C, a missing or out-of-range condition code (an unknown code inside a known group takes
the group's general one, e.g. 899 → 804), a malformed icon, an observation more than 15 minutes ahead of our clock or
more than a day old, more than 60 forecast slots. Humidity outside 0–100, negative wind, wind above 300 km/h or a
direction outside 0–360 are dropped from the observation while the rest is kept. A forecast slot whose low is above
its high is dropped. Any failure counts in `provider_health`; the third in a row alerts the admins (once an hour) and
the panel's home. A database error while storing one city is recorded the same way (its SQLSTATE only; the statement
goes to the error log), does not count as the source being down, and the run goes on to the next city.

## The nearest city (12.5) — computed in the browser only

The visitor's position must never reach the server. The API has no endpoint that takes coordinates, and none may be
added. The site does it like this:

1. Show the default city (`is_default`, Nablus) or the one remembered in the browser.
2. Only when the visitor presses "أقرب مدينة لي", call `navigator.geolocation.getCurrentPosition`.
3. With the cities' `latitude`/`longitude` from `/weather/widget` (or `/weather/cities`), compute the distance to each
   in the browser and take the smallest:

   ```ts
   const rad = (d: number) => (d * Math.PI) / 180;
   function km(a: { lat: number; lon: number }, b: { lat: number; lon: number }): number {
     const h = Math.sin(rad(b.lat - a.lat) / 2) ** 2
       + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(rad(b.lon - a.lon) / 2) ** 2;
     return 2 * 6371 * Math.asin(Math.sqrt(h));
   }
   ```

4. Remember the chosen city's slug in the browser (`localStorage`), never the position. Do not send the position in
   any request, analytics event or log.
