# QA environment

The checks before launch (the panel, the website, performance, failures, the database, outside security) run against a
full copy of the platform of their own, filled with made-up content by `raqam:demo`. This copy stays apart from the
developer's: it never reads `backend/.env`, and it never writes to `raqam`, `raqam_testing` or the developer's search
indexes, Redis databases or media.

| Part | QA | Developer (for comparison) |
|---|---|---|
| Settings | `backend/.env.qa` (git-ignored, mode 600, made by `setup` with new keys) | `backend/.env` |
| Database | `raqam_qa` | `raqam` (Pest: `raqam_testing`) |
| Search indexes | `raqam_qa_*`, with a key limited to them | `raqam_dev_*` |
| Redis | databases 14 and 15, prefix `raqam_qa_` | 3, 4 and 5 (tests 6 to 13) |
| Storage and media | `~/raqam-qa/storage` (`RAQAM_QA_HOME`), served from `~/raqam-qa/public` | `backend/storage` |
| API and panel | `http://127.0.0.1:8100` | `http://127.0.0.1:8000` |
| Website | `http://127.0.0.1:3300` (`SITE_URL`, and where the webhook goes) | `http://localhost:3000` |

Every command goes through `backend/scripts/qa.sh`, which names the QA settings explicitly (`php artisan --env=qa`,
`APP_ENV=qa` for the server). It refuses to run when `.env.qa` is missing or names another database or index prefix,
because Laravel would otherwise fall back to `.env`.

```sh
cd backend
scripts/qa.sh setup                  # .env.qa, the database, the storage folder, the search key, raqam:install
scripts/qa.sh admin                  # the QA admin; its password goes to ~/.raqam-qa-login (mode 600), never on screen
scripts/qa.sh demo --seed=2026       # 2000 posts (raqam:demo --posts=2000)
npm ci && npm run build              # the panel's theme into public/build (again after a change to it); serve refuses without it
scripts/qa.sh serve                  # the API and the panel; the panel's address is ADMIN_PATH in .env.qa
scripts/qa.sh worker                 # the queue: the webhooks to the website, the search index, picture sizes
scripts/qa.sh site build             # the website, built against the QA API (needs serve running)
scripts/qa.sh site start             # the website on 127.0.0.1:3300; its output also goes to ~/raqam-qa/site.log
scripts/qa.sh artisan <command>      # anything else, against raqam_qa
```

The website is built and served from a copy of `frontend/` in `~/raqam-qa/site` (with its own packages, installed
again only when the lock file changes), so the QA build never replaces the one the site's own tests use. It is given
only its QA values: the QA API, the QA site token and webhook secret, `SITE_URL`. Nothing comes from the developer's
shell or `frontend/.env.local`. Build it again after a change to `frontend/`.

`setup` refuses if `.env.qa` or the database already exist. To start again, drop `raqam_qa` and delete `.env.qa` by
hand. The script never drops or empties anything itself.

Outside services are off in the QA settings: no YouTube, OpenWeather, Brevo, IndexNow or Turnstile key, and mail goes
to the log. The Bank of Israel needs no key, so the currency fetch reaches the real bank when it runs, unless it is
pointed at the local stand-in (`e2e/qa/stand-in.mjs`, [resilience.md](resilience.md)).

## What the demo writes

`raqam:demo --posts=N` (11 to 2000, made-up content, refused in production) writes:
- every kind of post, in every category and section, over the past year;
- writers and guests, pictures in every size, videos and playlists;
- a year of exchange rates, and the weather;
- advertisers and campaigns, the services' made-up prices, the FAQ, pages, menus and the home page;
- the readers' side (`App\Demo\DemoActivity`):
  - newsletter subscribers in every state;
  - «اتصل بنا» messages, new and handled;
  - chat conversations, answered and not;
  - the last 90 days of statistics: page views (each day's hold the posts' own), visitors by source and by device,
    events, searches (some finding nothing), ad views and clicks on the days each campaign ran, service contacts,
    video plays, and missing pages.

Every e-mail address is `@example.org`, and every phone number is in the range kept for fiction (`+1 555 555 01xx`).
Statistics already in the database (the site's own counts) are kept as they are. `--remove` takes away only the
demo's own rows, as its record lists them.

## The panel, screen by screen (QA 2)

`e2e/qa/panel-crawl.spec.ts` opens every screen of the panel on the QA database:
- every list, create, edit and view screen and every settings page, taken from the panel's own route list;
- a real record for each edit or view screen, and for the post editor one post of every kind;
- at two sizes: a computer (1280 px) and a 360 px phone.

On each screen it checks four things:
- no server error;
- no error in the console;
- no English text: visible words, and also what a screen reader reads (a field's hidden label, `placeholder`, `aria-label`, `title`);
- nothing sticking out sideways (RTL), outside the parts that scroll on purpose.

```sh
backend/scripts/qa.sh serve                 # in one terminal
cd e2e && npx playwright test -c playwright.qa.config.ts
```

- **Signing in:** the run signs in as the QA admin, with the code read from the QA mail log. It keeps the session in `e2e/.qa/` (git-ignored) for the next run, because a new code is sent at most once a minute and three times an hour. The kept cookie is saved again as the server renews it.
- **The panel's address:** read from `ADMIN_PATH` in `.env.qa`, and never written into a test. Screens are named by what comes after it.
- **The run's own output stays local:** Playwright's timeout messages print full addresses.

Words in Latin letters on purpose are listed in the spec, each group with its reason:
- keys of the keyboard;
- names of services and formats;
- a browser and its system as the sign-in records name them;
- settings' names;
- file names.

A command's name in an alert (`queue:work`, `schedule:run`) is drawn as code, left to right, and code is not counted as
English text.

Stored values count as data: the audit trail's values before and after, and a field the trail records that has no
Arabic name yet (shown as stored, left to right; `AuditFieldNamesTest` fails on every such field in CI).

## The website, page by page (QA 3)

`e2e/qa/site-*.spec.ts` checks the QA website as a search engine and a visitor meet it, on the real API with the demo
data. It needs `serve`, `worker` and `site start` running.

```sh
cd e2e && npx playwright test -c playwright.qa.config.ts --project=site-webhook
```

That runs every part in order:

1. **The map** (`site-map`): every address of the sitemaps, every link between pages, and the site's own pages from
   its source (so a page nothing links to is found too). Each page is read once, as the server sends it. About 3000
   pages, 8 minutes. It is kept in `e2e/.qa/site/map.json` (git-ignored) for the parts after it.
2. **The checks** (`site-checks`), on the map:
   - every address answers, without a server error; the sitemap lists pages, never an address that moves;
   - no link inside the site is broken; every picture and video file arrives;
   - every page has a title and a description, and no two indexed pages share one (two posts with the same headline
     are the demo data's, and are only noted);
   - the canonical address is the page itself (a filtered list's is the list);
   - noindex only on a search, the newsletter's forms and a filtered list, never on a page of the sitemap;
   - the structured data reads, and each kind has its basic fields (an article: headline, description, picture, dates,
     author, publisher, address; a video: name, description, picture, date; a breadcrumb: its steps on the site…), and
     each kind of page carries its own (the home page a WebSite, a writer a ProfilePage…);
   - one h1 a page;
   - every indexed page is in the sitemap (or is a further page of a list), and another page links to it;
   - an address with nothing at it (an unknown post, list, page, video or currency) answers 404 within 10 seconds, in
     the site's frame, and a post under a wrong slug moves to its own address.
3. **The browser pass** (`site-browser`): every page opened in Chromium, down to its end, in 16 parts side by side:
   - no error in the console or in a script;
   - no violation of the content security policy;
   - no request of the site or of the platform's media that fails;
   - no picture that does not draw;
   - no id twice on a page.

   What the site asks of other hosts (YouTube's pictures) is answered on the machine.
4. **Accessibility** (`site-axe`): axe-core on one page of every kind, in the light and the dark theme, at 1440 and
   375 px. Not one serious or critical violation.
5. **The webhook** (`site-webhook`), last because it changes what the others read. The panel is signed in again first
   (`panel-session`): the parts before it take longer than a session's 30 idle minutes. Done in the panel, as an editor
   does:
   - a post published: it is on the site, on the home page and in the sitemap;
   - its address changed: the old one answers 301 to the new one, on every visit, once the webhook has arrived;
   - the post deleted: 404, and gone from the home page and the sitemap;
   - a home page block hidden and shown again;
   - a menu item renamed and named back;
   - the contact address changed and put back.

   Each change is put back, and the deleted post stays in the trash (a run stopped half way sends its post there too).

After any QA run, the teardown reads what the servers wrote while it ran. It fails the run on any of these:
- a `[csp]` line in the site's output (a violation a browser reported);
- an error of the site's server;
- an `ERROR` line of the API.

`--no-deps` runs one part again on the map already made.

## Performance (QA 5)

The database queries of every API endpoint and panel screen against their budgets, the heaviest queries with their
plans, and the load measured with k6: [performance.md](performance.md).

```sh
cd e2e && npx playwright test -c playwright.qa.config.ts --project=query-budget
```

## Failures, the scheduler, backups (QA 6)

Each part of the platform and each outside service stopped in turn, one whole day of the scheduler, and a backup
restored and checked: [resilience.md](resilience.md).

```sh
cd e2e && node qa/stand-in.mjs 8790                                     # the outside services, on the machine
cd backend && env <stand-in settings> APP_ENV=qa php scripts/qa-schedule-day.php
PGHOST=127.0.0.1 PGUSER=raqam scripts/check-restore.sh raqam_qa <restored copy>
```

## Security from outside (QA 8)

OWASP ZAP (run on the machine, no container) against the API, the website and the panel, and the checks a scanner
does not make (the headers on every kind of answer, what is never served, cookies, CORS, methods, redirects, a forged
Host, body sizes, the request limits): [security.md](security.md). The reports go to `private/qa-zap/` only.

```sh
cd backend && RAQAM_QA_QUERY_REPORT=false scripts/qa.sh serve           # as production answers: no query count
scripts/qa-zap.sh api | site-baseline | site-full | panel-baseline | panel-crawl
php scripts/qa-outside.php --limits
cd e2e && npx playwright test -c playwright.qa.config.ts --project=signin-limit
```
