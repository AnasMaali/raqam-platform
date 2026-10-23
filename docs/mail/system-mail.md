# System e-mail, admin alerts and the contact form (phase 8, package 1)

Decisions 20.1–20.13 of the requirements log. Everything here works without any paid service.

## Sending

- Every message goes through `App\Mail\SystemMail` and is made from an editable template (`email_templates`).
  `App\Mail\EmailCatalog` lists each message with the text it starts with, who it is from and its variables;
  the row is made the first time it is needed and never overwritten (`php artisan emails:install` makes all of them).
- Queued messages (`SendSystemEmail`) are tried 3 times (30 s, then 2 min apart). Each try is counted in
  `email_log`, which ends as `sent` or `failed` with a short reason (the SMTP password and any key are hidden).
- The sign-in messages (code, new device, password changed) are **never queued**: a stuck queue must not keep the
  admin out. They are sent right after the response, tried 3 times, and logged like the others.
- Switched-off templates (the dormant booking, payment and account messages) send nothing.
- `email_log` keeps 90 days (`emails:prune`, daily 02:30).
- Sender addresses come from the settings `mail.no_reply`, `mail.info`, `mail.ads`, `mail.booking`
  (empty = `MAIL_FROM_ADDRESS`). The sender name is the site name.

## Templates

- The admin edits the subject and the body (bold, italic, links, headings, lists) of each message in
  **System → Message templates**, with a preview and "send me a test".
- Variables are written `{name}`, e.g. `{الاسم}`, `{الرمز}`. An unknown variable is refused on save, and a required
  one (the code of a code message, the summary of the weekly summary) cannot be removed. Security messages cannot be
  switched off.
- Values go in by kind: text is escaped (a visitor's words never become HTML), a code is shown large, a link only
  when it is `http(s)`, and the weekly summary is a block the platform builds and escapes itself. Every value is
  filled in one pass, so a value that looks like `{الرمز}` stays as typed. The subject is always one line.
- The body is cleaned on save (no scripts, styles, images, event handlers; links `https`/`mailto` only) and put
  inside the RTL frame of Raqam (`resources/views/mail/system.blade.php`), with a plain-text copy.

## Admin alerts (the bell)

- `App\Panel\AdminAlerts::raise()` writes one row per active admin in `admin_notifications` and, when the alert has
  one, e-mails each of them. The same alert (its dedupe key) is raised at most once an hour, by the database clock.
- The bell is in the top bar of every panel page; it changes only when the admin clicks it (no polling: it would
  keep sessions alive). **System → Notifications** lists all of the admin's alerts.
- Alerts with an e-mail: a data source failing 3 times in a row (currencies, weather), a YouTube video turned
  private or deleted (weekly check), a queue job that gave up or a scheduled task that failed (a failed e-mail job
  rings the bell only), the disk below 10 % or 2 GB free (`system:check-disk`, hourly), a campaign ending within
  3 days (`ads:ending-alerts`, hourly; once per campaign and end, a new end warns again), and every contact message.
- The weekly summary (`admin:weekly-digest`, Saturday 09:00 Palestine time): new subscribers, ads (views, clicks,
  CTR), the chatbot's unanswered questions, new contact messages. Visits and the most read come with phase 9.
- The e-mail status on the panel's home now reads the log: the last message that finished decides (sent = working,
  failed = failing); a warning shows when messages wait in the queue for more than 10 minutes.

## Contact form (`POST /api/v1/contact`)

- From the website's server only: the browser posts to a route of the site on the same domain, which calls the API
  with `X-Raqam-Site-Token`, `X-Raqam-Visitor-Ip` and `X-Raqam-Visitor-Ua`. JSON only. See `docs/api/openapi.yaml`.
- Cloudflare Turnstile is checked on our server (`App\Support\Turnstile`): secret, the visitor's address, the
  action `contact` and one of our hostnames must match; if Cloudflare cannot be asked, the form is refused.
- A hidden field `website` catches robots (same answer, nothing kept). 5 messages an hour and 20 a day per visitor
  (IPv6 by /64), 10 requests a minute.
- Strict checks: a name with a link, an address or a domain is refused, no line breaks in name or subject, phone in
  international form (Arabic digits, spaces and `00` are accepted).
- The message is kept for **Contact → Contact messages** (opening one marks it read; archive, delete, reply by
  e-mail). The admins get the bell and an e-mail (reply-to = the sender), at most 20 e-mails an hour.
- The sender gets an automatic reply that carries only their name, and **one address gets at most one reply in
  24 hours, whoever asks**, so the form cannot be used to flood a stranger's mailbox.

## Environment

| Key | Meaning |
|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP (production refuses `log`/`array`). |
| `TURNSTILE_SECRET_KEY` | Required in production; empty elsewhere skips the check. |
| `TURNSTILE_HOSTNAMES` | The site's hosts, comma separated. |
| `API_SITE_TOKEN` | Required in production (the forms reach us only through the site). |
| `TURNSTILE_VERIFY_URL`, `ALERTS_DISK_PATH` | Tests only (production refuses the first). |
