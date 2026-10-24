# Online payment (decision 16) — built, switched off

Back end and tests only. While `booking` or `online_payments` is off (the database refuses payments without booking),
every payment route is a 404 and `payments:reconcile` does nothing.

## Pieces

| Piece | What it does |
|---|---|
| `App\Payments\PaymentGateway` | One provider: hosted checkout, server-to-server status, notification signature check, refund. Changing the bank = adding a class to `Gateways::CONNECTORS`. |
| `Gateways\FakeGateway` | The test bank (orders in the cache, HMAC-signed notifications with `PAYMENTS_FAKE_SECRET`). Refused in production. |
| `App\Payments\Payments` | `start()`: from the booking's private token, only while it waits for payment and has a fixed price; a random order id and an idempotency key per attempt; a pending attempt younger than 10 minutes is handed back. `sync()`: asks the bank and records the answer; final states never change again. |
| `App\Payments\Invoices` + job `SendInvoice` | Invoice in the same transaction as the payment (INV-<year>-<00001>, no gaps), one line, tax empty; PDF (mPDF, offline) kept private, attached once, mailed with the receipt. |
| `App\Payments\Refunds` | Full or partial, never beyond what is left (also enforced by the database). The refund row is written first; its id is the bank's idempotency key, so an interrupted refund is finished by the reconciliation, never sent twice. |
| `App\Payments\PaymentNotifications` | Every notification is kept in `payment_events`. A wrong signature is stored under its own `invalid-…` id (a forger cannot take a real event's id) and changes nothing. A valid one is acted on once (row lock + `processed_at`) by asking the bank again. |
| `payments:reconcile` | Every 15 minutes: attempts pending for more than 10 minutes are asked about; after 24 hours without payment an attempt is cancelled; interrupted refunds are finished. |

## Rules worth knowing

- No card detail ever reaches the server: the card is typed on the bank's page; a request with a card-like field is refused.
- "Paid" only when the bank, asked by this server, says so for the same amount and currency. A different amount is not a
  payment (failed + admin alert).
- A second successful payment of a paid booking is stored as `duplicate` (paid, outside the one-success rule) and the
  admins are alerted; it is refunded only by the admin's decision. It stays `duplicate` after a refund.
- If the admin moved the booking while the customer was paying, the payment is recorded and the admins are alerted.
- Amounts are integers in the currency's smallest unit, always with the currency.
- A notification larger than 64 KB is refused before anything is kept (413). A bank that does not answer is a 503 when
  starting and "pending" when the customer comes back; the reconciliation settles it.
- If the bank says an attempt closed here (failed, or given up after a day) was paid after all, the attempt is not
  reopened and the admins are alerted to decide.
- `retention:prune` removes notifications with a forged signature after 90 days; real ones stay.

## Before switching payments on (management)

- Merchant account with Bank of Palestine and its API documents; then write its connector.
- Currency, tax and the cancellation/refund policy (16.14).
- **The accountant decides whether a refund needs a credit note**; V1 records refunds and mails the customer but issues no credit note.
- Set the active row of `payment_gateways`; the test gateway can never be active in production.
