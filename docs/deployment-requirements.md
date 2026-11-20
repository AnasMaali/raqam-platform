# Deployment requirements

Things the production image and server must provide. The application checks some of them at boot
(`ProductionChecks`) and warns about others on the panel's home.

## Mandatory before launch (not optional)

These two belong to deployment, but the site must not go live without them (decision 2026-10-25):

1. **Turnstile on the admin sign-in** (24.9). The public forms already check Turnstile on the server; the panel's sign-in
   must too, so a script that knows the admin e-mail cannot try passwords or lock the account (today the limits are 5 a
   minute per address and e-mail, 20 an hour per e-mail, 30 an hour per address).
2. **Trusted proxies set to Cloudflare's ranges** (24.2). Behind Cloudflare every request reaches the server from a
   Cloudflare address. Until Laravel trusts exactly those ranges, `request()->ip()` is Cloudflare's, so every limit keyed
   by the visitor's address (sign-in, API, search, forms, the ad click link) counts all visitors together. The ranges come
   from Cloudflare's published list; the origin must also refuse connections from anywhere else.

Both are sign-in parts: they are built and tested step by step, and the launch checklist cannot be closed without them.

## Image library (GD) with AVIF

The media library writes every picture as AVIF, WebP and JPEG. A development machine may report AVIF as
available while refusing to write it, so the application probes the encoder once per process.

The production Docker image must ship a PHP build whose GD extension is compiled with `libavif`
(`--with-avif`) and can actually encode. If it cannot, pictures are served as WebP and JPEG only and the
panel's home shows a warning. Verify in the built image:

    php -r 'var_dump(imageavif(imagecreatetruecolor(1, 1)));'

It must print `bool(true)` (and write the image to stdout).

## ffmpeg and ffprobe

Short uploaded videos (decision 8.11) are measured with `ffprobe` and rewritten without their metadata
(GPS position, device, time) by `ffmpeg` (package `ffmpeg`, which provides both). Without them an upload
is refused with a clear message; a video is never stored with its metadata. Both programs are started
with `-protocol_whitelist file` and the input forced to MP4, so a crafted file cannot make them read
other files or reach the network. The image must contain both binaries on `PATH`.

## Meilisearch v1.54.3

Search (decision 10) runs on Meilisearch **v1.54.3**, pinned: `search:create-key` refuses any other version.
The engine listens on the internal Docker network only and is started with a master key. That master key
stays with the engine: the application never holds it.

1. With the master key, create the application's key once: `php artisan search:create-key` asks for the
   master key (hidden) and prints a key that can only index and search the indexes starting with
   `MEILISEARCH_PREFIX`. It cannot create or read API keys, dumps or snapshots, or touch other indexes.
2. Set `MEILISEARCH_HOST`, `MEILISEARCH_KEY` (that key) and `MEILISEARCH_PREFIX`. Production refuses to
   start without them, and `search:setup` refuses to run with a key that can manage API keys.
3. At every deploy: `php artisan search:setup` (creates the indexes, applies the settings and synonyms).
   On the first deploy, follow it with `php artisan search:reindex`.

The scheduler runs `search:sync-due` every minute (a safety net for the indexing jobs) and
`search:reindex` every night at 03:30 (repairs any drift). A queue worker must run: indexing happens in
queued jobs after each save, so a save never waits for the engine. When the engine is down, visitors
are answered from PostgreSQL (pg_trgm) and the jobs retry.

## Redis persistence (statistics)

Visit counters (decision 22) stay in Redis up to an hour before `stats:flush` writes them to PostgreSQL. Run Redis
with `appendonly yes` (and its data on a volume), so a restart loses at most a second of counts. A transfer never
lowers a stored figure, whatever Redis lost. Never run `FLUSHALL`, and never point `REDIS_DB` (queues and counters)
at the cache database: `cache:clear` empties the cache database only.

## The website: its address and content security policy

The website sends a content security policy on every answer (`frontend/next.config.ts`; `frontend/README.md`, "Content
security policy"). It is written when the site is built, so the build must see the production settings.

- **`SITE_URL`** is the address visitors use, exactly: `https://raqam.example` or `https://www.raqam.example`, whichever
  is the real one, and the other one redirects to it with a 301 in Cloudflare. The forms take a request only when its
  Origin is this origin character for character, so the wrong one refuses every form (403). The build and the server
  refuse to start with a path, a closing slash or plain http.
- **`MEDIA_ORIGINS`**: the origins the platform's pictures and uploaded videos are served from (the media subdomain,
  e.g. `https://media.example.org`), separated by spaces or commas, each an origin only (scheme, host and port). When it
  is empty the API's own origin is used, which is wrong as soon as the site reaches the API on an internal address: every
  picture would then be blocked. Set it whenever the media are not served from the API's origin.
- **The website's server listens on `0.0.0.0` or `localhost`** (`next start -H`, or `HOSTNAME` for the standalone
  server), never on one IP address such as `127.0.0.1`: the not-found page is a rewrite to the server itself under the
  name `localhost`, and a server bound to another name passes that rewrite on as an outside request, which fails (every
  address with nothing at it answers 500 after a long wait instead of 404).
- **Cloudflare features that rewrite pages must stay off** for the website's zone, or the policy blocks what they add:
  Rocket Loader, Email Address Obfuscation, the automatic Web Analytics beacon (Real User Monitoring), and Zaraz.
- **Rate limits in Cloudflare (WAF rules)** on `/api/csp-report` (anyone may send a report, and each one writes a line
  to the log) and on `/api/chat`. The routes check sizes and types, but not how often an address calls them.
- **Turnstile, checked by hand on staging** once with the real site key: the tests stand in for Turnstile's script, so
  only a real browser on staging shows that the widget draws, passes, and leaves no "Refused to…" line in the console
  (contact form and newsletter).
- **Watch the reports after each deploy**: lines starting with `[csp]` in the website's log name the directive, the
  blocked address and the page (no query, no visitor data). A new kind of line after a deploy is a page that needs a
  source added to the policy, or something the policy is right to block.

## Watching the platform: `/up` and `/livez`

Two addresses, for two different watchers (`App\Http\Controllers\HealthController`):

| | `/up` | `/livez` |
|---|---|---|
| For | the monitor outside the server (Uptime Kuma, decision 26) | the container's own health check (Docker) |
| Looks at | the database, Redis, the scheduler, the queue | nothing: PHP answers |
| Answers | 200, or 503 with which part fails | 200 `ok`, also during `php artisan down` |
| Who may ask | only with the token | anyone (it says nothing) |

### `/up`: the monitor outside, with its token

- **The token:** `HEALTH_TOKEN` in `.env`, at least 32 random characters (`openssl rand -hex 32`). Production refuses to
  start without it, or with a shorter one.
- **The monitor sends it** in the `X-Health-Token` header (Uptime Kuma: "Headers", as JSON). Never in the address:
  addresses end up in logs. The value is compared in constant time (`hash_equals`).
- **Without the header, or with a wrong value,** `/up` is the 404 of any page that does not exist. Nothing is looked
  at and no alert is raised, so `/up` cannot be used from outside to send the admins mail or to learn what is down.
  An empty `HEALTH_TOKEN` (outside production) means `/up` answers nobody.
- **The answer** is 200 when every part works and 503 when one does not, with which one:
  `{"status":"failing","checks":{"database":true,"redis":true,"scheduler":false,"queue":true}}`
  (`App\Panel\SystemWatch`, docs/qa/resilience.md). Nothing else is in it: no error, no address, no number.
- **The monitor asks every minute** and alerts on anything but 200. It is the one watcher that still sees a server
  where the scheduler and the queue worker have both stopped, or where PHP itself does not answer. `/up` also raises
  the admins' alert for what it finds.

### `/livez`: the container's health check

- **What it is:** PHP answers. No database, no Redis, no scheduler, no worker, no session; it answers during
  maintenance (`php artisan down`) too.
- **Why it is apart from `/up`:** a container that fails its health check is marked unhealthy, and whatever reads
  that (a restarter, a proxy, a deploy that waits for it) restarts it or stops sending it visitors. With `/up` there, a stopped queue worker or a Redis that does not answer would restart the web server, which is not
  what is broken, and would cut every visitor off for nothing. Those are for the monitor and the admins, not for a
  restart.
- **Only the web container** has it. The queue worker and the scheduler serve no HTTP: they are kept running by
  their restart policy, and `/up` sees them.

```yaml
# docker compose, the web service only (the port is the one the server inside the container listens on)
healthcheck:
  test: ["CMD", "curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/livez"]
  interval: 30s
  timeout: 5s
  retries: 3
  start_period: 30s
```

An image without curl can ask PHP itself:
`["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/livez') === 'ok' ? 0 : 1);"]`.

### What must run beside the web server

- **The scheduler** runs `php artisan schedule:run` every minute; its `system:watch` task beats, and looks at the
  queue. `/up` counts the scheduler as stopped five minutes after the last beat.
- **At least one queue worker** (`php artisan queue:work`, kept running by the container's restart policy). A job still
  waiting after 10 minutes counts as a stopped queue.
- **Timeouts** (`.env`, defaults in the code): `REDIS_TIMEOUT` 1 s and `REDIS_READ_TIMEOUT` 2 s, `MAIL_TIMEOUT` 10 s.
  Without them a Redis or a mail server that does not answer holds each request for PHP's 60 s. The website gives up
  on the API after 5 s.

## The database roles

The platform signs in to PostgreSQL with a role that can only read and write rows. A second role owns the schema and
is used by the deploy alone. A bug or a stolen password in the application can then change data, but it cannot drop
or alter a table, switch a trigger off, or change the audit log.

| Role | Signs in from | Can |
|---|---|---|
| `raqam_owner` | the deploy only (`php artisan raqam:install`, which migrates) | everything on the schema it owns |
| `raqam_app` | the web server, the queue worker, the scheduler (`DB_USERNAME` in `.env`) | rows only (below) |

`raqam_app`:
- **Rows only.** It can SELECT, INSERT, UPDATE and DELETE on the tables. It has no TRUNCATE, REFERENCES or TRIGGER, no
  CREATE on the schema or the database, and owns nothing. So it cannot DROP or ALTER anything, cannot switch a trigger
  off, and cannot replace a function.
- **No right on any sequence.** Every id is an identity column, and an identity column needs none.
- **Written once:**
  - the audit log and its changes: rows are added, never updated. They are deleted only by `audit:prune`, and the
    database itself refuses any row younger than two years;
  - an invoice and its lines: no update or delete, except attaching the PDF once (the `pdf_media_id` column);
  - a booking's status history: added, never changed.
- **Read only:** the views and the materialized view. Laravel's `migrations` list belongs to the deploy.

Make the roles once, as a superuser:

```sql
CREATE ROLE raqam_owner LOGIN PASSWORD '<from the password manager>';
CREATE ROLE raqam_app   LOGIN PASSWORD '<another one>';
CREATE DATABASE raqam OWNER raqam_owner TEMPLATE template0 ENCODING 'UTF8';
```

Then, after every deploy's migrations, as `raqam_owner` (it is idempotent, and covers the tables a new migration made):

```sh
psql -v ON_ERROR_STOP=1 -v owner=raqam_owner -v app=raqam_app -v db=raqam -d raqam -f database/roles/production-roles.sql
```

`database/roles/check-roles.sh` tests all of this on a database of its own (CI's Database workflow runs it):
- it builds the schema as the owner and applies the grants;
- it then tries, as the application, everything the application must not do (DROP, ALTER, TRUNCATE, CREATE, DISABLE
  TRIGGER, GRANT, changing the audit log or an invoice) and everything it must do (a booking through its history,
  payment, refund, numbered invoice and PDF, the audit prune, the synonym lock, the view totals).

It was also run against a copy of the QA data, with the API and every scheduled command signed in as `raqam_app`:
- all 52 API endpoints answered;
- every command ran, apart from the two outside fetches pointed at a closed port, and the one open point below.

**Open: refreshing the view totals.** In PostgreSQL 16 only a materialized view's owner can refresh it. So
`stats:flush`, signed in as `raqam_app`, cannot run `REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals`. The
choice is the owner's (a schema change, QA-7):
- a `SECURITY DEFINER` function owned by `raqam_owner` that only refreshes it, which the application may run;
- or the view owned by a third role that `raqam_app` is a member of.

Until then the check reports it as the one failure.

## Backups and restoring

Decision 24.18:
- every day, an encrypted copy of the database and the media goes to Cloudflare R2;
- kept: the last 7 days, 4 weeks and 6 months;
- a restore is tried every month.

Measured on the QA environment (44 MB, 2000 posts): the dump took 0.7 s (1.9 MB) and the restore 7.2 s.

### Each backup

1. `pg_dump -Fc -d raqam -f raqam-<date>.dump`, and at once, with nothing written in between, the facts of the same
   database: `scripts/check-restore.sh --facts raqam > raqam-<date>.facts`. The facts file holds the schema's checksum,
   every table's row count and checksum, and every sequence. It holds counts and checksums only, no data.
2. Encrypt the dump (never upload it in clear), write its SHA-256 beside it, and upload the dump, its checksum and the
   facts to the private R2 bucket. Keep the key outside the server and outside R2.
3. The media: the R2 media bucket (`MEDIA_DISK=r2`) with object versioning on, or the media disk's folder copied with
   the same keep rules.

Not in the backup, on purpose:
- **Redis.** Caches and sessions. The visit counters wait there at most an hour; `appendonly yes` keeps them over a
  restart.
- **Meilisearch.** Its indexes are made again from the database.
- **The `.env` files.** Kept in the password manager, never with the backups.

### Restoring

Into a **new** database, never over the one in use, so a bad copy changes nothing:

1. **Stop the writers.** `php artisan down` on the API, then stop the scheduler and the queue workers. The website goes
   on serving the pages in its cache.
2. **Fetch and open the copy.** Download the dump, its checksum and its facts; check the SHA-256; decrypt.
3. **Restore it.**
   ```sh
   createdb raqam_restore
   pg_restore -j 4 --exit-on-error --no-owner -d raqam_restore raqam-<date>.dump
   ```
   Any error stops the restore: drop `raqam_restore` and look at the error before trying again.
4. **Check it** (each must end with exit 0):
   ```sh
   scripts/check-restore.sh --against raqam-<date>.facts raqam_restore      # the same schema, rows and sequences
   psql -X -v ON_ERROR_STOP=1 -d raqam_restore -f scripts/restore-probes.sql   # the rules hold (rolled back)
   python3 ../database/tests/normalizer_parity.py raqam_restore
   ```
5. **Switch.** With nothing connected:
   ```sql
   ALTER DATABASE raqam RENAME TO raqam_before_<date>;
   ALTER DATABASE raqam_restore RENAME TO raqam;
   ```
   The settings stay as they are.
6. **Bring the rest in line with the restored data.**
   - `php artisan raqam:install` (safe to repeat).
   - `php artisan search:reindex`.
   - `php artisan cache:clear`: the cache database only, so the boards cached before the restore are not served.
   - Restore the media of the same day.
7. **Start again.** Start the queue workers and the scheduler, then `php artisan up`.
   - Press «تحديث الموقع الآن» on the panel's home, so the website draws its pages from the restored data.
   - `/up` must answer 200 (with the monitor's token).
8. **Keep the old database.** Keep `raqam_before_<date>` until the restored site has been checked, then drop it.

**The monthly drill:** steps 2 to 4 into a scratch database on the server (or on staging). Write down the time and
the size, then drop the scratch database. A drill never renames anything.
