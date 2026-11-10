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
are 404s without asking.

Any address with nothing at it is looked up in the platform's redirects (`/redirects/lookup`) before the 404: a
renamed page or service, or a move made by hand, answers 301 (or 302); a removed one is a 404 here (410 cannot be
given by a rewrite).

## Search

`/search` is dynamic and noindex; a query is never kept in a shared cache (`Cache-Control: private, no-store`). The
form is a GET form, so the results page works without JavaScript; the server's answer is the one the platform counts
(`track=1`). With JavaScript the results follow the typing: 250 ms after the last key the page asks `/api/search`, and a
request still on its way is cancelled. That route takes requests from the site's own pages only (`Sec-Fetch-Site`, or
the referrer), allows 60 a minute per visitor address (as the platform does), and never counts. The platform's
highlighting is inserted as HTML after every tag but `<mark>` is removed (`marked()` in `src/lib/search.ts`).

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
layout, so it brings its own document, fonts, styles and theme).

## Statistics

`src/components/site/ViewReporter.tsx` (in the root layout) reports each page view after the page has loaded and the
browser is idle, with `sendBeacon` (a `fetch` with `keepalive` where a beacon is refused), and a new view on each client
navigation. The not-found page marks itself, so it is reported as a missing page. No cookie, no library. The ads on the
page (`data-ad-campaign` on a slot with a campaign, never the house banner) are reported once each per view when at
least half is on screen, up to 8 in a report, with a random view id; what is still waiting is sent when the page is
hidden. The routes `/api/stats` and `/api/ads/impressions` take the site's own pages only, add the site token and the
visitor's address and browser, work out the post's id from the address (never from the browser), and always answer 204.
Ad clicks go through the platform's signed counting link (the slot's `click_url`).

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
npm run check:lighthouse     # with the production server on port 3100: SEO 100, accessibility ≥ 95, LCP < 2.5 s, CLS < 0.1
                             # (each page three times; the run with the median LCP counts)
```

The tests start the production server (port 3100) and a development server for the lab (port 3200). With `npm run dev`
already running, point the lab tests at it instead: `LAB_URL=http://localhost:3000 npm run test:e2e`.
