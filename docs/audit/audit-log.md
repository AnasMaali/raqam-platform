# Audit trail and sign-in attempts (decisions 22.14 – 22.17)

## What is written
`App\Audit\AuditLogger` (and the `Audited` trait on models) writes one `audit_log` row per add, change, delete, publish,
sign-in, sign-out, settings change and export, with one `audit_log_changes` row per field (before / after; secret-looking
fields as `[hidden]`, a post's body as its length). The address is stored only as an HMAC (`TRACKING_HASH_KEY`), with the
browser. `App\Auth\LoginAttempts` writes every sign-in outcome to `login_attempts`.

## Screens (group «النظام»)
- **سجل العمليات**: newest first; one search box for the user, the item (by its Arabic name or number), the field and the
  values before or after; filters by user, type, item, a period in Palestine days, and an IP address (typed in, turned
  into its HMAC and looked up; the address itself is never stored). The record page shows when, who, from where (device
  and HMAC) and every field before and after.
- **محاولات الدخول**: time, e-mail, the result in words, the device (named by its browser once the account approved it
  with a code; otherwise unknown), the address HMAC; search by e-mail, filters by result/reason, period and address.

## Nobody changes or deletes it
- The resources have no create/edit page and no edit, delete or bulk action; their `can*` methods say no.
- The models (`AuditLog`, `AuditLogChange`, `LoginAttempt`) refuse to save or delete (`ReadOnlyRecord`).
- The database refuses any UPDATE or DELETE on `audit_log` / `audit_log_changes` (`trg_audit_append_only`).

## Two years (22.17)
`audit:prune` (every night at 03:10) deletes audit rows (with their fields) and sign-in attempts older than two years, or
than `audit.retention_days` when that is longer; never younger. It runs in a transaction with
`SET LOCAL raqam.audit_prune = 'on'`, the only way the trigger lets rows go, and the trigger still refuses any row younger
than two years.
