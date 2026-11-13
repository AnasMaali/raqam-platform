# Raqam website

Next.js (App Router) site for the Raqam platform. Arabic only, right to left. It reads the platform API from its own
server; the browser never calls the API and never sees the site token.

## Local development

```bash
cp .env.example .env.local   # fill API_BASE_URL, API_SITE_TOKEN (same value as the backend), SITE_URL
npm ci
npm run dev                  # http://localhost:3000
```

The backend runs with `php artisan serve` (see the backend README). `/lab` holds design explorations: development
only, never indexed, a 404 in every production build.

## Checks

```bash
npm run lint                 # any warning fails it (accessibility and import rules are warnings by default)
npm run typecheck
npm run check:tokens         # colour contrast (AA, light and dark), DESIGN.md's table, no hand-written colours
npm run build                # also fails if the browser bundle mentions the site token
```

Settings are server-only. A `NEXT_PUBLIC_` variable whose name looks like a credential (TOKEN, SECRET, KEY, PASSWORD)
stops the build, and `scripts/check-client-bundle.mjs` scans the built browser files for the token name and value.

`npm audit --omit=dev --audit-level=low` fails CI on any known vulnerability in what reaches the visitor. The
development tools are audited too, report only: their fixes often wait for the tool's own release, and forcing a newer
version under a tool can break it without a sign.

The build needs the API: a failed request while building stops it, so a page frame is never built without its menus.

## The platform's webhook and the forms

- **Webhook.** The backend's `SITE_WEBHOOK_URL` is `https://<site>/api/revalidate`, with the same `SITE_WEBHOOK_SECRET`
  on both sides. The route accepts only a body signed with HMAC-SHA256 over `{timestamp}.{body}`
  (`X-Raqam-Signature: sha256=…`, `X-Raqam-Timestamp`), at most five minutes off this server's clock, compared in
  constant time. It refreshes the cached answers its type names (`src/lib/server/cache-tags.ts`) at once; an unknown
  type refreshes everything. Any refusal is the same short 401.
- **Newsletter.** `/api/newsletter/{subscribe,resend,confirm}` add the site token and the visitor's address and keep the
  platform's challenge in an httpOnly cookie. They accept JSON from the site's own pages only (Origin). The form needs
  `TURNSTILE_SITE_KEY`; the content security policy (hardening) must allow `challenges.cloudflare.com`.
- **Chat.** While the platform's `chatbot` switch is on, every page has the chat's button, drawn on the server and
  fixed to the corner. The chat's window, code and styles load only when it is pressed (`import()` in
  `src/components/chat/ChatLauncher.tsx`). `/api/chat` reads the welcome (GET) and passes messages on (POST), adding the
  site token and the visitor's address. It refuses any other Origin with 403 and is never cached. Answers go back as
  text only (`src/lib/chat.ts`): never the platform's HTML, a link only for a path of this site, and WhatsApp only as a
  `https://wa.me/` link. The conversation's key lives in the open window only.

## Articles

One template for every kind: `src/app/(pages)/[section]/[id]/[slug]`. A streamed page has already answered 200 by the
time it learns a post is gone, so `src/proxy.ts` asks the platform first (a `HEAD` on `/posts/{id}/{slug}`, whose
`X-Raqam-Path` header names the post's address) for article addresses only:
- an old slug, no slug, or another section's prefix: 301 to the current address;
- an unknown or removed post, or a malformed address: 404 (a rewritten page keeps its own status, so the platform's
  410 is served as 404: an exception to decision 23);
- otherwise, the page.

Each answer is kept in memory (at most 5000 addresses): a page or a move for 60 s, a missing post for 30 s. A 429 or
5xx is never kept, and the page decides alone, as it does when the platform does not answer within 2.5 s. If the
platform fails while the page renders, the page says «تعذّر تحميل المادة الآن» for that request only.

## Lists

A category, a tag, a writer and a guest share one template (`src/components/lists/ListView.tsx`): 12 items a page. The
first page is the list's own address (`/category/طاقة`); the others end in `/page/{n}`, and each is its own canonical
page. `src/proxy.ts` checks list addresses the same way as articles:
- an unknown list, a tag no live item carries, a page past the last, `/page/0` or a word: 404;
- `/page/1`: 301 to the list itself.

A category's RSS feed is at `/feeds/categories/{slug}` (the `feeds` prefix is reserved for feeds).

The seven sections and `/news` (`src/components/sections/SectionView.tsx`) use the same pages. Their filters are links
(`/podcast?season=2`). A filtered page is noindex, and its canonical address is the section's own page. The address check
also answers 404 for an unknown filter value, a hidden section and a page past the last. A section's feed is at
`/feeds/sections/{slug}`.

## Currencies and weather

`/currencies`, `/currencies/{code}` (the ISO code in lower case), `/weather` and `/weather/{slug}` (Arabic slugs) read
the platform's rates and weather (`src/lib/server/markets.ts`). `src/proxy.ts` answers 404 for an unknown or hidden
currency or city, and 301 for a code in another case (`/currencies/USD`). It also answers 404 for any other address of
two or three parts that is not a page (`/x/1/a`), which would otherwise reach the article's route and stream a 200.

`routeOf()` in `src/proxy.ts` names what an address is before anything is asked. `tests/e2e/proxy-routes.spec.ts`
reads every page and route under `src/app` and fails when the check would answer 404 to one of them, so a new route
must be taught to the check (or to `leftAlone`, the site's own folders) in the same change. A new route parameter needs a
sample value in that test.

The rate chart uses Recharts, loaded by the currency page only, with a dynamic import, when the chart comes near the
screen. A browser test checks that no other page loads it. The converter's rules (`src/lib/markets.ts`) follow the
platform: from a currency to the shekel at the buy rate, the other way at the sell rate.

The fake API's rates and cities come from `tests/fake-api/capture-markets.mjs`, apart from the content fixtures.

## Services, contact and the pages from the panel

`/services` and `/services/{slug}` read the platform's services (`src/lib/server/pages.ts`); a special offer ends by
itself, so their cache lives until the nearest offer's end at most. A press on a contact button posts to
`/api/services/{slug}/contact`, which counts it with the platform under the visitor's address (always 204 for the page).

`/contact` posts to `/api/contact`: same origin only, JSON only, 16 KB at most, the site token and the visitor's
address added here. The platform checks Turnstile (action `contact`), the honeypot `website`, the fields and its limits.

A page from the panel lives at the root (`src/app/(pages)/[section]/page.tsx`, named as the article's first part
because both are dynamic parts at the same level). `src/proxy.ts` asks the platform for `/pages/{slug}` before the page
streams; the site's own pages of one part are in `ownPages`, and the words the platform keeps (`StaticPages::RESERVED`)
are 404s without asking. Every address of one part goes through the check: a bare folder's name (`/brand`, `/api`) is a
404, but the lab's index, and a word that only starts like a folder (`/labour`) is asked about like any page.

`/team` (`/team`, the writers in the admin's order), `/faq` (`/faqs`, FAQPage), `/advertise` (`/advertise`: the places
with their sizes, the packages, the contact buttons) and `/sitemap` (the visitors' sitemap, from `/sections`,
`/categories`, `/pages`, the currencies and the cities) have routes of their own; the first three take the panel's page
text when it is published. The team and the questions are kept ten minutes at most (no webhook names them). The fake
API's answers for them come from `tests/fake-api/capture-p1.mjs`.

Any address with nothing at it is looked up in the platform's redirects (`/redirects/lookup`) before the 404: a
renamed page or service, or a move made by hand, answers 301 (or 302); a removed one is a 404 here (410 cannot be
given by a rewrite).

## Search

`/search` is dynamic and noindex; a query is never kept in a shared cache (`Cache-Control: private, no-store`). The
form is a GET form, so the results page works without JavaScript; the server's answer is the one the platform counts
(`track=1`). With JavaScript the results follow the typing: 250 ms after the last key the page asks `/api/search`, and a
request still on its way is cancelled. That route takes requests from the site's own pages only (`Sec-Fetch-Site`, or
the referrer), allows 60 a minute per visitor address (as the platform does), and never counts. The platform's
highlighting is inserted as HTML only through `marked()` (`src/lib/search.ts`): the text between the marks is read back
from the platform's escaping and escaped once, and only the bare `<mark>` and `</mark>` stay tags, so any other tag shows
as text and a «<» in a title reads as «<».

The fake API's answers come from `tests/fake-api/capture-search.mjs` (with the search engine running).

## Newsletter pages

`/newsletter` holds the newsletter form with its own place (`newsletter_page`); noindex. `/newsletter/unsubscribe?s=&t=`
is the link in every newsletter: opening it changes nothing (mail scanners open links too); its button posts to
`/api/newsletter/unsubscribe` (same origin, JSON), which asks the platform. The page sends no Referer, so the link's
signature never leaves. A mail program's one-click unsubscribe (RFC 8058, `List-Unsubscribe=One-Click` posted to the
same link) is sent by `src/proxy.ts` to `/api/newsletter/one-click`; the signature is its proof.

## Files for search engines

`/robots.txt`, `/llms.txt`, `/sitemap.xml` (an index) with `/sitemaps/{pages,posts-N,news,videos}.xml`, and the site's
RSS feed `/feeds/posts` are written by the site from the platform's `/robots`, `/llms`, `/sitemaps/*` and `/feeds/posts`.
The platform lists every public address; `notYetBuilt` in `src/lib/server/seo-files.ts` drops the ones the site does not
serve yet (remove a prefix when its page is built), so no sitemap sends a crawler to a 404. A test asks every address in
the sitemap and expects 200. robots.txt adds the site's own `/api/`.

The IndexNow key file is `/{INDEXNOW_KEY}.txt` (`INDEXNOW_KEY`, the backend's value); `src/proxy.ts` sends it to
`/api/indexnow`. Without a key there is no such file. The site's own root files are listed in `ownFiles`; any other
one-part address with an extension is a 404.

`src/app/global-error.tsx` is the 500 page for a failure in the page frame itself (Next.js renders it without the root
layout, so it brings its own document, fonts, styles and theme). What it says is `src/components/site/ServerError.tsx`;
the lab shows the same component at `/lab/foundations` («صفحة الخطأ (500)»), since the page itself only appears when
the frame fails.

## Statistics

`src/components/site/ViewReporter.tsx` (in the root layout) reports each page view after the page has loaded and the
browser is idle, with `sendBeacon` (a `fetch` with `keepalive` where a beacon is refused), and a new view on each client
navigation. The not-found page marks itself, so it is reported as a missing page. No cookie, no library. The ads on the
page (`data-ad-campaign` on a slot with a campaign, never the house banner) are reported once each per view when at
least half is on screen, up to 8 in a report, with a random view id; what is still waiting is sent when the page is
hidden. The routes `/api/stats` and `/api/ads/impressions` take the site's own pages only, add the site token and the
visitor's address and browser, work out the post's id from the address (never from the browser), and always answer 204.
Ad clicks go through the platform's signed counting link (the slot's `click_url`). An ad drawn after the page (the popup,
the phone's bar) announces itself with a `raqam:ad` event, so it is watched and counted the same way.

## Floating ads

The popup and the phone's bar (`src/components/ads/FloatingAds.tsx`) exist only while the platform's switch
(`ad_popup`, `ad_mobile_sticky`) and the slot are both on and the slot has a campaign or the «أعلن معنا» banner
(`src/lib/server/floating-ads.ts`). Their code loads with `import()` after the page's load, when the browser is idle,
and only at the width each one shows at. Between 768 and 1023 px there is neither.

- **The phone's bar** (below 768 px) is fixed to the bottom, above the phone's own bar (`safe-area-inset-bottom`). It is
  drawn at the visitor's first scroll, press or key, when the browser stops looking for the largest paint: a picture
  arriving later on a page whose largest paint is text would otherwise become its LCP. The server keeps its place from
  the first paint, so its arrival moves nothing. `data-ad-bar` and `--ad-bar-h` go on the
  body, then `globals.css` adds the body's padding (the footer ends above the bar) and raises `--float-bottom` (the
  chat's button and «للأعلى» sit above it).
- **The popup** (from 1024 px) is the browser's modal dialog. It opens after the visitor's first scroll, press or key,
  never on arrival. If another window is open (the chat), it waits for the next interaction. It shows at most once in
  the slot's `frequency_cap_hours` (24 when empty), kept in `localStorage` (`raqam:ad-popup`, no cookie). Without
  storage it never shows.

The fake API turns both on with a made-up campaign each. Every browser test starts as a visitor who has just seen the
popup (`playwright.config.ts`), except `tests/e2e/ads.spec.ts`.

## Video and characters

`/video` (and `/video/page/{n}`, `?section=`), `/video/{id}`, `/video/playlists/{slug}` and
`/awareness/characters/{slug}` read the platform's `/videos`, `/playlists` and `/characters`
(`src/lib/server/videos.ts`). `src/proxy.ts` answers 404 for an unknown video, playlist or character, a library page past
the last and a section without videos, and 301 for `/video/page/1`. A filtered library is noindex and points to
`/video`. The video card (`src/components/video/VideoCard.tsx`) is shared with the home page's video chapter.

## Security headers

`next.config.ts` (`headers()`) puts six headers, and the content security policy below, on every answer: pages,
missing and moved pages, the routes under `/api`, the feeds and sitemaps, and the build's own files. They are the same
for every visitor, so the page cache and the CDN keep working.

- `Strict-Transport-Security: max-age=31536000; includeSubDomains`. It is not on the browsers' preload list: a domain
  is hard to take off it.
- `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin`: another site learns where
  a visitor came from, never which page.
- `X-Frame-Options: DENY`: no site may frame this one.
- `Cross-Origin-Opener-Policy: same-origin`. Lighthouse opens its blank page on `robots.txt` for that reason
  (`scripts/check-lighthouse.mjs`): from `about:blank`, the trace lost the page's start in about one run in ten.
- `Permissions-Policy`. The visitor's position goes to the site itself, for «أقرب مدينة لي», which asks only on that
  press. Four features go to the YouTube player: autoplay, encrypted-media, picture-in-picture and fullscreen. Every
  other feature is off. A frame gets a feature only when the header hands it over and the frame's `allow` asks for it,
  so `LiteVideo`'s `allow` names the same four.

`tests/e2e/security-headers.spec.ts` checks each value exactly on each kind of answer. It also checks the features the
page and the player get, and that the console has no warning about them.

## Content security policy

`next.config.ts` adds `Content-Security-Policy` and `Reporting-Endpoints` to the same answers. The policy is the same
for every visitor and every page (no nonce), so the static shells, the page cache and the CDN keep working: a nonce
would make every page dynamic.

| Directive | Sources | For |
|---|---|---|
| `default-src` | `'self'` | |
| `script-src` | `'self' 'unsafe-inline' https://challenges.cloudflare.com` | the build's files, Next's inline page data, Turnstile |
| `script-src-attr` | `'none'` | no inline event handler (`onerror=`, `onclick=`) ever runs |
| `style-src` | `'self' 'unsafe-inline'` | the stylesheets, and the `style` attributes React writes |
| `img-src` | `'self'`, the media origins, `https://i.ytimg.com` | the platform's pictures; a video's picture the platform could not keep |
| `font-src` | `'self'` | the fonts are served by the site (`next/font`) |
| `media-src` | the media origins | videos uploaded to the platform |
| `frame-src` | `https://www.youtube-nocookie.com https://challenges.cloudflare.com` | the YouTube player, Turnstile |
| `connect-src` | `'self'` | the browser talks to the site's own routes only |
| `object-src`, `base-uri` | `'none'` | |
| `form-action` | `'self'` | |
| `frame-ancestors` | `'none'` | with `X-Frame-Options: DENY` |
| `upgrade-insecure-requests` | | only when every media origin is https |

- **Why `'unsafe-inline'` for scripts:** Next writes its page data as inline scripts whose text changes with every page
  and every refresh, so no hash can name them, and a nonce needs dynamic pages. A hash or a nonce in the list would also
  make the browser ignore `'unsafe-inline'`. What the policy does stop: scripts from anywhere else, `eval`, inline event
  handlers, plugins, a changed `<base>`, and pictures, frames and requests to unknown places. Text from the platform is
  cleaned by the platform and escaped by React.
- **The media origins** are `MEDIA_ORIGINS` (origins only; the build refuses anything else), or else the origin of
  `API_BASE_URL`. Like the rest of the policy they are read at build time.
- **In development** scripts may also use `eval` (React's development build needs it).
- **Reports** go to `/api/csp-report` (`report-uri`, and `report-to` through `Reporting-Endpoints`). The route takes only
  the two CSP report types (`application/csp-report`, `application/reports+json`), at most 8 KB however it is sent,
  answers `no-store`, and writes one line per violation: `[csp] <directive> blocked <address> on <page>`, both addresses
  without their query or fragment. Nothing about the visitor is written.

`tests/e2e/content-security.spec.ts` goes through every page of the sweep to its end, plays a video (YouTube's player,
a picture from YouTube, an uploaded video), opens the chat, opens the popup and the phone's bar, sends the contact form
with Turnstile's script and frame, and asks for the nearest city: not one violation, in the browser's events or its
console. Code slipped into a page (an `onerror` handler, a script from elsewhere) does not run and is reported. The
route's tests run against a server of their own and read its log.

## Page cache

`cache-handler.js` keeps rendered pages in memory (`PAGE_CACHE_MAX_MB`, default 256; the least recently used go
first). Next.js's own cache writes each page to a file named after its address, and a percent-encoded Arabic slug
passes the 255-byte limit of a file name, so those pages were never kept. The webhook's tags empty the matching pages.
After a restart the cache is empty, and each page renders on its first visit. One server only: a second instance
would need a shared store.

## Browser tests

The tests read a fake API (`tests/fake-api/`, made-up demo content captured by `capture.mjs`), never a real platform.

```bash
npm run fake-api &                                       # http://127.0.0.1:4010/api/v1
export API_BASE_URL=http://127.0.0.1:4010/api/v1 API_SITE_TOKEN=ci-only-site-token-not-a-secret SITE_URL=https://example.org \
  TURNSTILE_SITE_KEY=1x00000000000000000000AA SITE_WEBHOOK_SECRET=ci-only-webhook-secret-not-a-secret
npm run build
npm run test:e2e             # 375, 768 and 1440 px; the sweep 320 to 1920 px, held sideways, 200% zoom
npm run check:lighthouse     # with the production server on port 3100: SEO 100, accessibility ≥ 95, LCP under the cap,
                             # CLS < 0.1 (each page three times; each figure is the median of the runs)
```

### Lighthouse

Two ways to slow a page down to a mid-range phone on a slow 4G line, with the same phone and line:

- **devtools, the gate.** The browser is really held to the phone's speed and the line's while the page loads, and
  the page is timed as it loads. Its numbers stay close from run to run, so a red build means the page got slower.
- **simulate, a report only** (`LIGHTHOUSE_THROTTLING=simulate`; in CI also `LIGHTHOUSE_REPORT_ONLY=1`, which prints
  without failing). The page loads at full speed and Lighthouse estimates the slow phone afterwards, as PageSpeed
  Insights does. The estimate counts every script that started before the LCP reached the screen as part of the LCP.
  On a page whose largest element is text in the first frame, the framework's scripts start a few milliseconds before
  or after that frame, so the same page reads about 1.8 s in one run and 2.7 s in the next: in the slow runs 8 of the
  site's scripts had started before the LCP, in the fast ones 0 to 2. Nothing in the page decides that order, so as a
  gate it failed builds at random. It still runs in CI, so its figures stay in sight.

The gate's LCP cap comes from the CI's own machines: `.github/workflows/lighthouse-calibration.yml` (run by hand from
the Actions page) measures every page of `check:lighthouse` ten times, each on its own runner, and prints each page's
median, worst and spread. The cap is the highest worst plus a small margin, and stays under 2.5 s.

The cap is **2.0 s**. It comes from a calibration of ten runs a page on the CI: the highest worst was 1.71 s
(`/podcast`), and most pages spread 0.01 to 0.03 s between their fastest and slowest run, around a median of 1.41 to
1.51 s. So a page fails when it gets about half a second slower than its usual figure, not on a slow run of the machine.
The simulated report is held to 2.5 s instead (the Core Web Vitals' line): its estimate reads 0.3 to 1.2 s above the
gate's on the same page, so the gate's cap would mark nearly every page.
Run the calibration again after a change that moves the pages' LCP, or when a page joins the list.

Each Lighthouse line also names the LCP element, the four parts of its time (to the first byte, until its resource was
asked for, its download, its drawing) and, for every run, how many of the site's scripts had started before it showed.
`LIGHTHOUSE_RUNS` sets the number of runs; with five or more each page also gets its median, worst and spread.

The font file is made by `scripts/subset-font.sh` (needs `fonttools` and `brotli` from pip) from Alexandria's variable
font; see DESIGN.md, Typography.

The tests start the production server (port 3100) and a development server for the lab (port 3200). With `npm run dev`
already running, point the lab tests at it instead: `LAB_URL=http://localhost:3000 npm run test:e2e`.
