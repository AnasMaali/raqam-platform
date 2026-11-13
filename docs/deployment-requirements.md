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
