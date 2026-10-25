# Online booking (decision 15) — built, switched off

Everything lives in the back end and its tests. There is no page on the site and no screen in the panel. While the
`booking` feature switch is off, every booking route of the API is a 404 (the same answer as an address that never
existed), and the services API offers no «احجز الآن» button.

Until their site pages exist, the four dormant switches (`booking`, `online_payments`, `customer_accounts`,
`multi_admin`) are turned on only from the server, with the same dependency rule and an audit entry:
`php artisan feature:switch booking on` (or `off`). In the panel's «مفاتيح التشغيل» screen they stay locked while off;
once on, the panel can switch them off as an emergency stop (payments first, then booking).

## Pieces

| Piece | What it does |
|---|---|
| `App\Http\Middleware\RequireFeature` (`feature:<key>`) | 404 while a switch is off, before the request limit counts the request. Used by every dormant system. |
| `App\Booking\BookingDesk` | Takes a request from the site: strict checks, a honeypot, Turnstile (action `booking`), limits per visitor (an IPv4 address or an IPv6 /64), the price and currency of the moment copied onto the booking, up to 3 private attachments through `MediaUploader` (JPEG/PNG/WebP/PDF by content), the confirmation for the customer and the alert for the admins. |
| `App\Booking\BookingLink` | The private status link: 32 random bytes, only the SHA-256 is stored. The token rides after the `#` of the site's address (`raqam.booking.track_path`), so no server log or Referer carries it. |
| `App\Booking\BookingTracker` | What the status page shows: reference, service, current stage, the stages reached. Nothing the customer typed, no internal note. |
| `App\Booking\BookingStatuses` | Moves a booking to another stage by inserting a `booking_status_history` row (the database checks the move and refuses any direct change). The payment stages are refused while `online_payments` is off. The customer is told when the stage has `notify_customer`. |

## API (site server only, documented in `docs/api/openapi.yaml`)

- `POST /api/v1/bookings` — JSON, or multipart/form-data with `attachments[]`. Answers 202 with the reference and the link.
- `POST /api/v1/bookings/track` — `{token}` in the body; the progress, or 404.

## Rules worth knowing

- A contact is stored once per e-mail (`customers`). A later request never overwrites the stored name or phone (anybody
  can type anybody's address); when it differs, the booking's internal note lists what differs.
- One address gets at most 3 confirmation mails a day, whoever asks (`raqam.booking.confirmations_per_address_day`).
- The status-change mail cannot carry the link (only its hash exists); it points to the first message.
- The booking mails are switched on in the template catalogue: nothing sends them while the feature is off.

## The admin's side (15.10, switched off)

`App\Booking\BookingInbox`, for the panel screen that comes with booking: the list (stage, service, Palestine days, a
search on reference, name or e-mail), one booking with its customer, service, stages and attachments, the planned date
(Palestine time, can be cleared), the published item it led to (live items only) and dated internal notes that are added,
never replaced. Every write is audited; the stage still moves only through `BookingStatuses`.
