# Services and prices (decision 14)

Managed from the panel (**Services → Services and prices**). In V1 this page is how a service is booked: by WhatsApp,
call or e-mail; online booking is built but off (decision 15).

## A service

Group («تغطيات إعلامية» / «إعلانات»), name, address (`/services/{slug}`, made from the name), short description (the
card), full description (cleaned like every text of the site), what it includes (up to 20 lines), picture (public, from the
library), the section whose published work is shown as examples, its own FAQ (up to 20, each shown or hidden), SEO
texts, order (drag in the list) and shown/hidden. Deleting moves it to the trash (it can come back).

`php artisan services:install` adds the six starting services of 14.5 **hidden and with "price on request"**; it never
touches a service that exists (same name or address) and never writes a price.

## The price

* **Mode** (14.3): fixed, "from", or "price on request" (no amount, currency, note or offer).
* **Amount** (14.2, 16.12): stored as a whole number of the currency's smallest unit (agorot, cents): `16.12` is `1612`.
  Typed as digits (Western or Arabic) with at most two decimals; negative, zero, above 1,000,000, exponents, thousands
  separators and anything else are refused, never rounded. The database keeps the last word (`money_minor ≥ 0`,
  offer < price).
* **Currency**: shekel or dollar only. **Note**: up to 80 characters («للحلقة», «للشهر»).
* **Special offer** (14.4): a lower price until a date and time (Palestine), at most a year ahead. While it lasts it is
  the price and the regular one is shown as "was"; at its end the regular price comes back by itself (answers are never
  cached past that moment). An offer that has ended shows empty in the form, with a note.
* **Audit** (14.12): every price change is in the audit log with who and when, as the raw columns and as one readable
  line from → to, e.g. `150.00 ILS (سعر ثابت) للحلقة → 175.50 ILS (سعر ثابت) للحلقة`.

## Contact buttons (14.8, 14.9)

From the general settings: WhatsApp number and ready message (the service's name is added: «…»), phone, e-mail and the
ready e-mail subject (`contact.email_subject`, the service's name is added). A button whose setting is empty is `null`
(hide it). The site reports each press to `POST /api/v1/services/{slug}/contact` **through its own route** (token,
visitor address and browser in headers, like ad views): people only, once per 30 seconds per visitor, service and
button, at most 30 an hour per visitor; counts per service, button and Palestine day (`service_contact_clicks_daily`),
shown in the list for the last 30 days.

## Booking (14.10)

`booking.enabled` is false while the `booking` switch is off: show no booking button. When it is switched on, every
service gets `booking.path` (`/services/{slug}/book`) by itself.

## API

`/services` (the page's intro from the system page "services", then the groups and their cards), `/services/{slug}`
(details, FAQ, examples, SEO and `structured_data`: schema.org Service with an Offer, `priceSpecification.minPrice` for
"from", `priceValidUntil` during an offer, no Offer for "price on request"), `/advertise` (13.14: the ad slots that are on
with their sizes, the visible services of the «إعلانات» group as packages with prices, contact buttons, booking flag).
A change in the panel sends the webhook `{"type": "services", ...}`.
