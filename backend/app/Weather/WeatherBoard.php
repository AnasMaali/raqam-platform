<?php

namespace App\Weather;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The weather as the site shows it (decisions 12.6–12.11), read only from our own tables: a page never waits for
 * OpenWeather. Cached, and dropped whenever a city's weather or the city list changes.
 */
final class WeatherBoard
{
    public const ATTRIBUTION = 'Weather data © OpenWeather';

    /** Older than this, the weather shown is marked stale (the source has not answered for a while). */
    private const STALE_HOURS = 3;

    private const TTL = 600;

    /** @return list<array<string, mixed>> the visible cities, in the admin's order */
    public function cities(): array
    {
        return $this->remember('cities', fn () => $this->cityRows()->map(fn ($c) => $this->city($c))->values()->all());
    }

    /** @return list<array<string, mixed>> every visible city with its weather now, small enough for a header widget */
    public function widget(): array
    {
        return $this->remember('widget', fn () => $this->cityRows()->map(fn ($c) => $this->city($c) + ['now' => $this->now((int) $c->id, short: true)])->values()->all());
    }

    /** @return array<string, mixed>|null */
    public function show(string $slug): ?array
    {
        // Only a known city gets an entry of its own in the cache: addresses made up by a visitor leave nothing behind.
        if (! in_array($slug, array_column($this->cities(), 'slug'), true)) {
            return null;
        }

        return $this->remember('city:'.md5($slug), function () use ($slug) {
            $city = $this->cityRows()->firstWhere('slug', $slug);

            return $city === null ? null : $this->city($city) + ['now' => $this->now((int) $city->id), 'forecast' => $this->forecast((int) $city->id)];
        });
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return ['attribution' => self::ATTRIBUTION, 'units' => ['temperature' => 'C', 'wind' => 'km/h']];
    }

    public static function changed(): void
    {
        DB::afterCommit(fn () => Cache::forever('weather:version', (int) Cache::get('weather:version', 0) + 1));
    }

    private function cityRows()
    {
        return DB::table('cities')->join('weather_locations', 'weather_locations.city_id', '=', 'cities.id')
            ->where('weather_locations.is_active', true)->orderBy('weather_locations.sort_order')->orderBy('cities.id')
            ->get(['cities.id', 'cities.name_ar', 'cities.slug', 'cities.latitude', 'cities.longitude', 'weather_locations.is_default']);
    }

    /** @return array<string, mixed> */
    private function city(object $c): array
    {
        return [
            'slug' => $c->slug, 'name' => $c->name_ar, 'path' => '/weather/'.$c->slug, 'is_default' => (bool) $c->is_default,
            'latitude' => (float) $c->latitude, 'longitude' => (float) $c->longitude,
        ];
    }

    /** @return array<string, mixed>|null */
    private function now(int $cityId, bool $short = false): ?array
    {
        $o = DB::table('weather_observations')->join('weather_conditions', 'weather_conditions.code', '=', 'weather_observations.condition_code')
            ->where('city_id', $cityId)->orderByDesc('observed_at')->first();
        if ($o === null) {
            return null;
        }

        $today = DB::table('weather_forecasts')->where('city_id', $cityId)->whereRaw("forecast_date = (now() AT TIME ZONE 'Asia/Hebron')::date")->first();
        $temperature = (float) $o->temperature_c;
        $now = [
            'temperature' => $temperature,
            'condition' => ['code' => (int) $o->code, 'text' => $o->description_ar, 'icon' => $o->icon_key.'-'.($o->is_day ? 'day' : 'night')],
            'is_day' => (bool) $o->is_day,
            'observed_at' => Carbon::parse($o->observed_at)->utc()->format('Y-m-d\TH:i:s\Z'),
            'stale' => Carbon::parse($o->observed_at)->lt(now()->subHours(self::STALE_HOURS)),
        ];
        if ($short) {
            return $now;
        }

        return $now + [
            'feels_like' => $o->feels_like_c === null ? null : (float) $o->feels_like_c,
            // Today's low and high: the forecast's, stretched to include what was just measured.
            'today' => $today === null ? null : ['min' => min((float) $today->temp_min_c, $temperature), 'max' => max((float) $today->temp_max_c, $temperature)],
            'humidity' => $o->humidity_pct === null ? null : (int) $o->humidity_pct,
            'wind_kmh' => $o->wind_kmh === null ? null : (float) $o->wind_kmh,
            'wind_deg' => $o->wind_deg === null ? null : (int) $o->wind_deg,
        ];
    }

    /**
     * Five days, today first. The free forecast covers the next 120 hours, so today and four whole days are what it
     * can always fill; a sixth, partial day is never shown.
     *
     * @return list<array<string, mixed>>
     */
    private function forecast(int $cityId): array
    {
        return DB::table('weather_forecasts')->join('weather_conditions', 'weather_conditions.code', '=', 'weather_forecasts.condition_code')
            ->where('city_id', $cityId)->whereRaw("forecast_date >= (now() AT TIME ZONE 'Asia/Hebron')::date")
            ->orderBy('forecast_date')->limit(5)->get()
            ->map(fn ($f) => ['date' => $f->forecast_date, 'min' => (float) $f->temp_min_c, 'max' => (float) $f->temp_max_c,
                'condition' => ['code' => (int) $f->code, 'text' => $f->description_ar, 'icon' => $f->icon_key.'-day']])->values()->all();
    }

    private function remember(string $key, \Closure $build): mixed
    {
        $version = (int) Cache::get('weather:version', 0);

        return Cache::remember("weather:v{$version}:{$key}", self::TTL, $build);
    }
}
