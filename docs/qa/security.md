# Security from outside (QA-8)

The platform as an attacker meets it, from outside, on the QA environment ([README.md](README.md)): the API on
`127.0.0.1:8100` (with the panel) and the website on `127.0.0.1:3300`, reading `raqam_qa`. Nothing else was scanned.
The back end answered as production does for this: `APP_ENV=qa` (not `local`), `APP_DEBUG=false`, and no query count
(`RAQAM_QA_QUERY_REPORT=false scripts/qa.sh serve`), so the scans saw the real error pages and headers.

Two parts:
- **OWASP ZAP**, five automation plans (`backend/scripts/zap/`), run by `backend/scripts/qa-zap.sh`;
- **the checks a scanner does not make**, or makes without saying why: `backend/scripts/qa-outside.php` (the headers on
  every kind of answer, what must never be served, cookies, CORS, methods, open redirects, a forged Host, body sizes,
  the request limits) and `e2e/qa/signin-limit.spec.ts` (the sign-in's limit, in a real browser).

Every alert is below: real ones were fixed (a failing test first, then the fix, then the fix broken on purpose to see
the test fail), the others have their reason. The ZAP reports (HTML and JSON) are in `private/qa-zap/` only.

## ZAP

| | |
|---|---|
| Version | **2.17.0**, the latest stable release (the weekly builds after it are pre-releases) |
| Package | `ZAP_2.17.0_Linux.tar.gz` from the zaproxy GitHub release, SHA-256 `efe799aaa3627db683b43f00c9c210aea0b75c00cc8f0a0f0434d12bb3ddde5a`, the same as the one published on the release |
| Add-ons | the bundled release rules, plus the beta rules `pscanrulesBeta` 50 and `ascanrulesBeta` 67 (with `commonlib` 1.44.0, `network` 0.33.0, `oast` 0.26.0), installed by ZAP from its own add-on list, which checks each file's hash |
| Where | `~/tools/zap/ZAP_2.17.0` and its home `~/tools/zap/home`, outside the repository; Java 17 |

```sh
mkdir -p ~/tools/zap && cd ~/tools/zap
curl -fLO https://github.com/zaproxy/zaproxy/releases/download/v2.17.0/ZAP_2.17.0_Linux.tar.gz
echo "efe799aaa3627db683b43f00c9c210aea0b75c00cc8f0a0f0434d12bb3ddde5a  ZAP_2.17.0_Linux.tar.gz" | sha256sum -c
tar -xzf ZAP_2.17.0_Linux.tar.gz
ZAP_2.17.0/zap.sh -cmd -dir ~/tools/zap/home -host 127.0.0.1 -config api.enabled=false -config callhome.tel.enabled=false \
  -addoninstall pscanrulesBeta -addoninstall ascanrulesBeta
```

How `qa-zap.sh` runs it:
- **Bound to this machine.** ZAP's proxy listens on 127.0.0.1 only, on a free port. Its API is off, it never calls
  home or looks for updates, and its out-of-band service is off. Its callback server listens on every address unless
  told otherwise, so it is bound to 127.0.0.1 too (the first run showed it on `*`). ZAP stops when its plan ends.
- **No secret in the repository.** The plans name `${QA_SITE_TOKEN}`, `${QA_ADMIN_PATH}` and `${QA_PANEL_COOKIE}`;
  the script reads them from `backend/.env.qa` and from a sign-in made just before the panel crawl. ZAP does not read
  the environment inside a replacer rule (it sent the literal `${QA_SITE_TOKEN}`), so the script writes a copy of the
  plan with the values (mode 600). It also writes those values into its log and its saved options, so each run uses a
  copy of ZAP's home in a folder of its own, deleted when the run ends. ZAP's output and the reports are checked for
  the values, and any found would be replaced by `[removed]` (none was found).
- **Reports** in `private/qa-zap/` (git-ignored): `<plan>.html` and `<plan>.json`; the first run's in
  `private/qa-zap/before-fixes/`.

| Plan | What | Size |
|---|---|---|
| `api` | active, every operation of `docs/api/openapi.yaml`, with the site token (the site-only routes too) | 88 addresses, 68 active rules at medium strength, 2 threads |
| `site-baseline` | passive: the spider from the home page, the sitemap and robots.txt, no form sent | 2731 addresses |
| `site-full` | active, on one page or route of each kind: the home page, an article, `/news`, search, `/contact`, and the routes of the contact form, the newsletter, the chat and the CSP reports (sent with the site's Origin and a visitor address, as its pages send them) | 10 requests |
| `panel-baseline` | passive, signed out: the sign-in page and where it leads | 28 addresses |
| `panel-crawl` | passive, signed in: every page the spider reaches with a GET (no form sent, never the sign-out) | 173 addresses |

No active scan on the panel: an active scan sends forms, and Filament's actions (publish, delete) would run.

The OpenAPI reader reports "attribute info.license.identifier is missing": OpenAPI 3.1 makes the identifier optional
(a proprietary licence has none), and every operation was still read, so that one error does not stop the plan.

## Alerts

By risk; each alert once, with where it was raised. Instances of a passive alert repeat on every answer of the kind.

| Risk | Alert | Where | Verdict |
|---|---|---|---|
| High (medium confidence) | SQL Injection (40018) | site: `POST /api/contact`, `email`; the second scan only | Reason (N): disproved |
| High (medium confidence) | Source Code Disclosure, File Inclusion (43) | site: `POST /api/chat`, `website`; the second scan only | Reason (O): disproved |
| Medium | Backup File Disclosure (10095) | site: `/news/4338.bak`, `.old`, `.zip` and 15 more answered **200** | **Real, fixed** (1) |
| Medium | CSP: `script-src`/`style-src` `'unsafe-inline'` (10055) | site, panel | Reason (A); for the panel, a decision below |
| Medium | CSP: Wildcard Directive (10055) | panel | Reason (A); a decision below |
| Medium | Insecure HTTP Method: DELETE (90028) | API: `OPTIONS /api/v1/account` names DELETE | Reason (B) |
| Low | X-Powered-By: `PHP/8.4.26` (10037) | every back-end answer | **Real, fixed** (2) |
| Low | Cross-Origin-Resource-Policy missing (90004) | API and panel answers | **Real, fixed** (3) |
| Low | Cross-Origin-Resource-Policy missing (90004) | site | Reason (C) |
| Low | Cross-Origin-Embedder-Policy missing (90004) | site, panel | Reason (D) |
| Low | X-Content-Type-Options missing (10021), Permissions Policy not set (10063), CORP | the panel's `/css`, `/js`, `/fonts` | Server, written down (E) |
| Low | Cookie without HttpOnly: `XSRF-TOKEN` (10010) | panel | Reason (F) |
| Low | CSP notices: `report-uri` is deprecated (10055) | site | Reason (G) |
| Low | Big Redirect Detected (10044) | panel, signed out | Reason (H) |
| Low | Timestamp Disclosure, Unix (10096) | panel, its scripts | Reason (I) |
| Info | Suspicious Comments (10027) | site and panel scripts | Reason (I) |
| Info | User Controllable HTML Element Attribute (10031) | site: `/podcast?season=1`, `/search?q=` | Reason (J) |
| Info | User Agent Fuzzer (10104) | API and site: the chat | Reason (K) |
| Info | Content-Type missing (10019) | site: `/api/` (308) | Reason (L) |
| Info | Storable / non-storable content (10049), session management and sign-in identified (10112, 10111), modern web application (10109), cookie slack detector (90027) | everywhere | Descriptions of what is there, no flaw |

Apart from the backup files of fix 1 and two alerts disproved by hand (N, O), nothing was found by the 68 active
rules on the API, nor on the site's pages and routes: no SQL injection (with the PostgreSQL, MySQL, Oracle, SQL Server, SQLite and HSQLDB timing rules) or NoSQL
injection, no cross-site scripting (reflected, stored, out of band), template injection (plain and blind), command,
code, expression-language, XPath, XSLT or SOAP injection, XXE or entity expansion, server-side include, path traversal,
remote file inclusion, SSRF, cloud metadata, CRLF, HTTPoxy, open redirect, session fixation, CSRF token, CORS,
"403 bypass", parameter tampering, buffer overflow, format string, integer overflow, Log4Shell, Spring4Shell or
Shellshock, and no `.env`, `.git`, `.svn`, `.htaccess`, hidden or backup file, directory listing or source disclosure
on the API.

After the fixes the API and the site were scanned again: the X-Powered-By and Cross-Origin-Resource-Policy alerts
are gone from the API and the backup files from the site. The site's second scan raised the two alerts (N) and (O),
which the first did not: both come from the request limits, which make the same request answer differently from one
moment to the next.

### Fixed

1. **A file's address under a section answered 200 with a page** (`/news/4338.bak`, and outside the scan
   `/storage/logs/laravel.log`, `/vendor/autoload.php`, `/a/b.sql`). The check before the page (`src/proxy.ts`) left
   alone every address deeper than the root with an extension, as if it were one of the site's files; it reached a
   page's route, which streamed its answer before it found nothing: the not-found page, under status 200 and sometimes
   404, rendered through the framework's error page. Nothing secret was served: the body was the site's not-found
   page. But a scanner, or a search engine, saw pages at addresses that have none. Now such an address is the
   site's only in its own folders (`_next`, `brand`, `og`, `sitemaps`, …) and answers 404 before any page anywhere
   else. Tests: `frontend/tests/e2e/proxy-routes.spec.ts` (the check's answer, and a request to the built site).
2. **Every back-end answer named PHP and its version** (`X-Powered-By: PHP/8.4.26`): PHP sends it unless its ini
   says `expose_php = Off`. The headers' middleware now takes it off, so no answer depends on how the server's PHP
   was set up; production should use PHP's production ini as well (docs/deployment-requirements.md). Test:
   `e2e/tests/outside.spec.ts`, against a server run with `expose_php` on (the browser tests' server now always is).
3. **No Cross-Origin-Resource-Policy on the back end's answers.** Every answer now says `same-origin`: no page of
   another origin may load one as a picture, a script or a frame. None needs to: the browser never calls the API, and
   the media are files the web server sends, outside PHP. Test: `SecurityHeadersTest`.

Found by the checks below, not by ZAP:

4. **Answers made before the application's own code had no security headers**: a body larger than PHP takes (413)
   and the maintenance page (503), on the panel and the API. The headers' middleware was the innermost global one, so
   an answer from a middleware outside it never passed through it. It is now the outermost. Tests:
   `SecurityHeadersTest` (413, 503 for the panel and the API, and a 500 with no trace).
5. **An unsigned preview link told which posts exist**: `/preview/4338` answered 403 and `/preview/1` 404, so anyone
   could count the drafts by trying numbers. The post was looked up before the signature was checked; the signature
   now comes first, and every made-up link answers 403. Test: `PostLifecycleTest` (a draft, a post in the trash and no
   post at all answer the same).

Every fix was broken again on purpose and its test failed (7 of 7): the headers' middleware back to the innermost
(413 and 503 tests), X-Powered-By left in (the browser test), Cross-Origin-Resource-Policy left out, the binding back
before the signature, and the site's check three ways (every deeper file left alone, seen by the check and by a
request to the built site; and the site's own folders refused, which the route test catches).

### Reasons

- **(A) `'unsafe-inline'`, and the panel's short policy.** On the site, scripts may run inline because Next.js writes
  each page's data as inline scripts, different on every page, so neither a hash nor a nonce can name them on pages
  that are cached and prerendered (`frontend/README.md`, "Content security policy"). The rest of the policy holds:
  no inline event handler (`script-src-attr 'none'`), no eval, scripts only from the site and Turnstile, requests to
  the site only, no plugin, no framing. The panel had only `frame-ancestors 'none'; base-uri 'self'; form-action 'self';
  object-src 'none'` when it was scanned (no `default-src`, hence "wildcard"). Since then it has a full, enforced
  policy (`App\Http\PanelContentPolicy`): `default-src 'self'`, scripts from the panel only, requests to the panel
  only, pictures and videos from the panel, the media's origins and YouTube's pictures, no frame, no plugin. It keeps
  `'unsafe-inline'` (Filament's inline scripts have no nonce) and `'unsafe-eval'`: Livewire 3 bundles Alpine's standard
  build, which runs every expression written in the page through `new Function`, and Livewire 3 has no build without
  it. The whole panel browser suite runs with the policy on and fails on anything it blocks.
- **(B) DELETE on `/api/v1/account`** is the customer's own account deletion (`docs/api/openapi.yaml`): it needs a
  customer session, and the customer accounts are switched off, so it answers 404. `OPTIONS` and a GET's 405 name the
  method of a switched-off route; the API description is public, so this tells nothing new.
- **(C) No Cross-Origin-Resource-Policy on the site**: its files are meant to be loaded by others: the newsletter's
  pictures in mail programs, the social cards, the feed readers. Its JSON routes already refuse other origins (403),
  and an answer with `nosniff` and a JSON or HTML type is not read by another page's `<script>` or `<img>`.
- **(D) Cross-Origin-Embedder-Policy** asks for cross-origin isolation, which only pages using shared memory need.
  Nothing here does, and `require-corp` would block the YouTube player and Turnstile.
- **(E) The files the web server sends itself** (the panel's styles, scripts and fonts; the media while they stay on
  the local disk) never reach PHP, so the application cannot add headers to them. Here PHP's own development server
  sends them; in production it is Caddy, and the headers are now written down for it
  (docs/deployment-requirements.md, "The web server in front").
- **(F) `XSRF-TOKEN` without HttpOnly** is Laravel's CSRF cookie: the panel's script reads it to send it back in
  `X-XSRF-TOKEN`; it holds no session. The session cookie is `__Host-raqam_session`: Secure, HttpOnly, SameSite=Strict.
- **(G) `report-uri` beside `report-to`**: browsers without the Reporting API (Firefox, Safari) read only `report-uri`.
- **(H) The redirect's body** is Laravel's "Redirecting to" page naming the sign-in address it redirects to; nothing
  else.
- **(I) Timestamps and comments** are numbers and words ("query", "from", "user", "select") inside the minified scripts
  of the site, Filament and Livewire, not comments left by the platform.
- **(J) The "user-controlled attribute"** on `/podcast?season=1` is the viewport's `initial-scale=1` matching the
  value; on search, the term is written into the field's value escaped (`"><script>` comes back as
  `&quot;&gt;&lt;script&gt;`), and an unknown season answers 404.
- **(K) The User Agent Fuzzer** saw the chat answer differently for old browsers and bots: by design (`App\Support\BotAgent`
  keeps bots out of the counts and the conversations).
- **(L) `/api/`** is Next.js's 308 to `/api`, which has no body.
- **(N) "SQL injection" on the contact form's e-mail.** ZAP says why: "data was NOT returned for the original
  parameter". The original request came back refused by the contact limit (429, 10 a minute per visitor, and the scan
  is one visitor), and `… AND 1=1 -- ` and `… OR 1=1 -- ` came back with the longer 422 of the form's check. By hand,
  each from its own visitor address: the plain e-mail is taken (202), and `AND 1=1`, `AND 1=2`, `OR 1=1` and
  `' OR '1'='1` all answer the same 422 ("البريد الإلكتروني غير صحيح") before any query; the message is written with
  bound values and the column is the `email_address` domain. No such row is in `contact_messages`.
- **(O) "Source code disclosure" through the chat's `website` field.** `website` is the honeypot: when it is filled,
  the chat answers "no answer" whatever its value (`chat`, `chat.php`, `../../.env` or random letters), with nothing
  read from any file. The answers differ only by the conversation's random token, and by the limit (10 messages a
  minute per visitor) during the scan.

## The checks a scanner does not make

`php backend/scripts/qa-outside.php --limits` against the fixed QA environment: **55 of 55** passed, and 11 notes.
The full table is `private/qa-zap/outside-checks.md`; what it checks:

| Area | Checked | Result |
|---|---|---|
| Headers, site | CSP (with `frame-ancestors 'none'`), HSTS, `X-Frame-Options`, `nosniff`, Referrer-Policy on an HTML 200 and 404, a 301, a JSON route and its 403 and 405, RSS, the sitemap, robots.txt, llms.txt, a script, a stylesheet, a font and a picture of the build | all 14 kinds, no `X-Powered-By` or `Server` |
| Headers, back end | the same (HSTS aside) on JSON 200, 404, 403, 422 (an empty form, text that is not UTF-8), 405, `/up` without its token, `/livez`, an HTML 404, an unsigned preview, an unsigned private file, the sign-in page, the panel signed out (302), Livewire's script; 413, 503 and 500 in `SecurityHeadersTest` | all, after fixes 2 to 4 |
| HSTS, back end | sent on https requests only (the proxy ends TLS; the trusted proxies make Laravel see https) | by design, in `SecurityHeadersTest` |
| Never served | `.env`, `.env.qa`, `.git/`, `composer.json/lock`, `package.json/lock`, `artisan`, `storage/`, `storage/logs/laravel.log`, `vendor/`, `phpinfo.php`, `.htaccess`, the schema, build folders and more: 36 addresses on each server | none, after fix 1 (the site answered 200 for three of them) |
| Source maps | the site's build files of the home page: no `.map`, no `sourceMappingURL` | none. Livewire's own map is served by Livewire: the open-source library's, nothing of the platform's |
| Cookies | the sign-in page's session (`__Host-`, Secure, HttpOnly, SameSite=Strict, path `/`) and `XSRF-TOKEN` (Secure, SameSite=Strict); the newsletter's challenge (HttpOnly, SameSite=Strict, path `/api/newsletter`, Secure when `SITE_URL` is https) | as stated. Secure cookies need https; the browser keeps them on `http://127.0.0.1` because it treats that host as secure, so the QA environment works |
| CORS | a preflight and a GET from `https://evil.example` to the API and to the site's routes | no `Access-Control-Allow-*` at all |
| Methods | TRACE on the API, the panel and the site | 405, never echoed; but the site answers **500** to TRACE on most addresses (Node refuses the method), and a page answers PROPFIND like a GET: for the server in front (M) |
| Open redirects | 15 addresses (`//evil.example`, `/\evil.example`, encoded slashes, `next=`/`redirect=` on the site, the panel and the newsletter, a forged ad click, the redirects lookup) | none leaves the site |
| A forged Host | `Host` and `X-Forwarded-Host: evil.example` on a 301 of the site, its home page, its sitemap, the API and the sign-in page | no address written from it. The QA back end writes the sign-in page's addresses from a forged `Host`, as any non-production server does; production writes every address from `APP_URL` (`AppServiceProvider::pinUrls`, `SecurityHeadersTest`) |
| Body sizes | 1.2 MB to the chat, the contact form and the CSP reports on the site, and to the API's contact route | 413 on the site at once (4 and 8 KB limits); 422 on the API (`post_max_size` is the upload limit, 48 MB here) |
| Limits | 11 messages to the chat, 11 contact forms, 11 newsletter sign-ups from one visitor; 130 API requests without the token | 429 from the 11th (chat, contact, newsletter: 10 a minute each) and the 115th (120 a minute, some used just before); the 429 has `Retry-After` and the headers |
| Limits, CSP reports | 50 in a row | all 204: no limit in the site; the limit is a Cloudflare rule (docs/deployment-requirements.md) |
| Limits, sign-in | `e2e/qa/signin-limit.spec.ts`: seven wrong passwords for one made-up e-mail | the first five go to the code page as any password does; the sixth and seventh stay on the form, refused unchecked, one `throttled` row |

- **(M) Methods and the static files** are the server's job, written down in docs/deployment-requirements.md, "The web
  server in front": only GET, HEAD, POST and OPTIONS reach the site and the back end; no version in `Server`; PHP's
  production ini; the headers on the files the server sends itself. The website's server cannot refuse TRACE itself:
  Node refuses it before any of the site's code runs.

## Decisions asked for

1. **A fuller policy for the panel**: decided and done after this package (`App\Http\PanelContentPolicy`, enforced,
   with reports to `/csp-report`).
2. **The server in front** (Caddy, phase 11): the items above are written as requirements, to be checked on staging
   the way `qa-outside.php` checks the QA environment.

## Follow-ups (after the review of QA-8)

- **No dot in a slug.** The website now answers 404 before any page for an address deeper than the root that ends in
  `.<letters or digits>` (fix 1). A slug with a dot would have been such an address, so `slug_text` refuses `.` (the
  migration edited in place, before launch). Every slug writer already made dot-free slugs: the panel's forms and the
  services through `UniqueSlug::for` or `ArabicSlug::make`, which turn every dot into a dash (posts, categories, tags,
  authors, guests, organisations, sectors, pages, playlists and podcast seasons, characters, services), and the
  install's fixed lists (sections, pages, characters, cities, services). There is no import. Redirects hold paths,
  not slugs, and the site asks the redirect lookup before it answers 404, so an old `.html` address can still be moved.
  Tests: the domain (SQL suite and `FoundationSchemaTest`), `ArabicSlugTest` (dots become dashes) and
  `SlugWritersTest` (every slug of the 13 tables after the install is as `ArabicSlug::make` writes it).
- **Cross-Origin-Resource-Policy: same-origin on every PHP answer** blocks nothing another origin loads. The media
  (pictures in the site and in the newsletter's mails, the characters' animations, the feeds' enclosures, the social
  cards' pictures) are files the web server or R2 sends, outside PHP. The one file route PHP has is
  `private-media/{path}`: booking attachments, seen in the panel (same origin), and invoices, which go out as mail
  attachments. Opening any link is a navigation, which the header does not touch. Test: `SecurityHeadersTest` (no
  route serves the public media, and `private-media` is the only file route).
