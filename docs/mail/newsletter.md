# Newsletter (phase 8, package 2)

Decisions 2.2 and 19.1–19.11. The free Brevo plan is enough; Brevo is optional (empty key = subscribers stay with us).

## Subscribing (`POST /api/v1/newsletter/subscribe`, `/resend`, `/confirm`)

- From the website's server only (site token + visitor address), JSON only; Turnstile (action `newsletter`) checked
  on our server. Forms: footer, end of a post, newsletter page (`placement`), plus the page path.
- The answer is the same for every address: a `challenge` (opaque, encrypted by us; the site keeps it, e.g. in an
  httpOnly cookie) and, when the limits allow, a 6-digit code by e-mail from the platform's mailbox (not Brevo).
- The code: 30 minutes, 5 tries, a new one after a minute (a new code replaces the old). It is an HMAC (key
  `ADMIN_CODE_KEY`) of the address, a random value inside the challenge, and the digits, so a code works only with
  the challenge it was sent for. The checks run inside a Timebox.
- Limits: **one address gets at most 3 codes an hour and 6 a day, whoever asks** (same answer, nothing sent beyond
  that); 10 requests and 30 confirmations an hour per visitor (IPv6 by /64); 10 requests a minute.
- Nothing about the person is stored before they confirm. On confirmation the subscriber row gets the consent proof
  (19.9): when they asked, when they confirmed, the page, the form (`source_placement`, new column), an HMAC of the
  address they asked from. Confirming again (after an unsubscribe) records the new consent.

## Unsubscribing (19.5)

- Every campaign carries Brevo's own unsubscribe link (`{{ unsubscribe }}`; Brevo also adds the one-click
  `List-Unsubscribe` header) and the subscriber's link to our page: `SITE_URL/newsletter/unsubscribe?s=ID&t=SIG`
  (an HMAC of the subscriber and address; kept in Brevo as the contact attribute `RAQAM_UNSUB`).
- The site's page (or a one-click POST to it) calls `POST /api/v1/newsletter/unsubscribe` with `subscriber` and
  `signature`.

## Brevo (19.4, 19.6, 19.10)

- `BREVO_API_KEY` (header `api-key` only), `BREVO_LIST_ID`. Every change of a subscriber queues a job that reads the
  row when it runs: active = in the list; unsubscribed/bounced = out of the list and blocked; deleted = contact
  deleted. Failures are retried and counted in provider health (3 in a row alert the admins).
- **Webhook** `POST /api/v1/newsletter/brevo-webhook`: Brevo does not sign its messages, so it sends our secret
  `BREVO_WEBHOOK_TOKEN` in the header `X-Raqam-Brevo-Token` (wrong = 404), and a message is only a hint: the
  subscriber changes only after Brevo's API says the contact no longer receives the list (blocked, out of the list,
  unsubscribed from it, or deleted). One check a minute per address.
- Set up once: `php artisan newsletter:brevo-setup https://<api host>/api/v1/newsletter/brevo-webhook` (creates the
  attribute and the webhook for unsubscribed, hard bounce, spam and deleted contacts).

## The weekly newsletter (19.6)

- On the day and time set in Settings → Newsletter (default Saturday 09:00, Palestine), `newsletter:weekly-draft`
  (every 15 minutes) makes the week's draft: the last 7 days' published posts, featured first, then newest, at most 8.
- It goes to Brevo as a **draft campaign** for our list (never scheduled, never sent from here) and the admins get a
  bell. The admin reviews and edits it in Contact → Weekly newsletters (subject, intro, posts and their order; each
  save updates Brevo's draft), previews it, then sends it from Brevo. A sent newsletter cannot be edited.
- The campaign is RTL with Raqam's colours (tables, works in Gmail/Outlook/phones); posts link to `SITE_URL`.

## Numbers (19.10)

- Contact → Newsletter subscribers: active, new in 7/30 days, unsubscribed, bounced, and 12 weeks of growth;
  export to Excel (current or all, with the consent facts; text cells only; every export is audited); delete
  (row, codes and Brevo contact; the audit keeps no address).
- Opens, clicks, deliveries and unsubscribes per newsletter come from Brevo (`newsletter:refresh-stats`, hourly,
  and on demand); a campaign Brevo sent is marked sent.

## Environment

| Key | Meaning |
|---|---|
| `SITE_URL` | The website's https address (required in production). |
| `BREVO_API_KEY`, `BREVO_LIST_ID` | Brevo; empty key = no connection. |
| `BREVO_WEBHOOK_TOKEN` | Random secret, 32+ characters (required when the key is set). |
| `BREVO_API_BASE` | Tests only (production refuses it). |
