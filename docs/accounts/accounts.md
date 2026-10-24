# Customer accounts (decision 17) — built, switched off

Back end and tests only. While `customer_accounts` is off every `/api/v1/account/*` route is a 404. A customer is a row
of `users` with type `customer` and never enters the panel (the panel takes live, active staff only; tested with the
right password).

## Pieces

| Piece | What it does |
|---|---|
| `App\Accounts\AccountCodes` | 6-digit e-mail codes bound to an encrypted challenge (the newsletter's pattern): 10 minutes to confirm an address, 60 to reset a password, 5 tries per code and challenge, one code a minute and 3 an hour / 6 a day per address. |
| `App\Accounts\CustomerAccounts` | Register (nothing stored until the code: name, phone and password hash ride in the challenge), sign in (the admin's limits, one general message, Timebox, `login_attempts` with the visitor's HMAC), reset, profile, password change, self-delete. |
| `App\Accounts\CustomerSessions` | A random token the site keeps in its own HttpOnly cookie and sends as `Authorization: Bearer`; only its SHA-256 is a Redis key, 30 days at most. Each session carries a fingerprint of the password: a change or reset ends every session. |
| `App\Accounts\CustomerLibrary` | Saved items (live posts only), followed categories and sections (public ones only), «حجوزاتي» (bookings made while signed in; older ones are never linked, 2.6). |
| `RequireCustomer` middleware | Site only + a live customer session; the customer is put on the request, never on the framework's guard. |

## Self-delete (17.7, decision 2026-10-24)

Refused while a booking of the person is still open. Otherwise: the account row becomes «حساب محذوف» with a
`deleted-<id>@deleted.invalid` address, no phone, a random password, stopped and soft-deleted; saved items, follows and
devices are removed; bookings lose the account link; the booking contact of the same address becomes «عميل محذوف»
with no phone or company (`customers.phone` is nullable for this); sign-in rows and the mail log lose the address.
Invoices keep what they were issued with: a legal record the database never lets change. The address can register again.

## Mail

`account_verify_code`, `password_reset_code` and `account_password_changed` are locked on (nothing sends them while the
feature is off).
