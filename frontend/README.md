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
time it learns a post is gone, so `src/proxy.ts` asks the platform first (a `HEAD` on `/posts/{id}/{slug}`) for article
addresses only:
- an old slug, or no slug: 301 to the current address;
- an unknown or removed post, or a malformed address: 404 (a rewritten page keeps its own status, so the platform's 410
  is served as 404);
- otherwise, the page.

If the platform does not answer within 2.5 s, the page decides alone.

## Browser tests

The tests read a fake API (`tests/fake-api/`, made-up demo content captured by `capture.mjs`), never a real platform.

```bash
npm run fake-api &                                       # http://127.0.0.1:4010/api/v1
export API_BASE_URL=http://127.0.0.1:4010/api/v1 API_SITE_TOKEN=ci-only-site-token-not-a-secret SITE_URL=https://example.org \
  TURNSTILE_SITE_KEY=1x00000000000000000000AA SITE_WEBHOOK_SECRET=ci-only-webhook-secret-not-a-secret
npm run build
npm run test:e2e             # 375, 768 and 1440 px; the sweep 320 to 1920 px, held sideways, 200% zoom
npm run check:lighthouse     # with the production server on port 3100: SEO 100, accessibility ≥ 95, LCP < 2.5 s, CLS < 0.1
```

The tests start the production server (port 3100) and a development server for the lab (port 3200). With `npm run dev`
already running, point the lab tests at it instead: `LAB_URL=http://localhost:3000 npm run test:e2e`.
