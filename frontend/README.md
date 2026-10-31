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
npm run lint
npm run typecheck
npm run check:tokens         # colour contrast (AA, light and dark), DESIGN.md's table, no hand-written colours
npm run build                # also fails if the browser bundle mentions the site token
```

Settings are server-only. A `NEXT_PUBLIC_` variable whose name looks like a credential (TOKEN, SECRET, KEY, PASSWORD)
stops the build, and `scripts/check-client-bundle.mjs` scans the built browser files for the token name and value.

The build needs the API: a failed request while building stops it, so a page frame is never built without its menus.

## Browser tests

The tests read a fake API (`tests/fake-api/`, made-up demo content captured by `capture.mjs`), never a real platform.

```bash
npm run fake-api &                                       # http://127.0.0.1:4010/api/v1
API_BASE_URL=http://127.0.0.1:4010/api/v1 API_SITE_TOKEN=ci-only-site-token-not-a-secret SITE_URL=https://example.org npm run build
npm run test:e2e             # 375, 768 and 1440 px; the sweep 320 to 1920 px, held sideways, 200% zoom
npm run check:lighthouse     # with the production server on port 3100: SEO 100, accessibility ≥ 95, LCP < 2.5 s, CLS < 0.1
```

The tests start the production server (port 3100) and a development server for the lab (port 3200). With `npm run dev`
already running, point the lab tests at it instead: `LAB_URL=http://localhost:3000 npm run test:e2e`.
