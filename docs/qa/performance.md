# Performance on the QA environment (QA-5)

What the platform asks of the database and how fast it answers, measured on the QA environment (`raqam_qa`, 2000
posts of `raqam:demo`; see [README.md](README.md)). The numbers below were taken on the developer's machine (8 cores,
WSL2; PostgreSQL 16, Redis and Meilisearch local; the API on `php -S` with 4 workers, the website on `next start`).
They compare states of the code on one machine; they are not the production server's numbers.

## The query budget

### How the count is made

With `QUERY_REPORT` on, every answer of the back end says in `X-Raqam-Queries` how many database queries it made
(`App\Http\Middleware\QueryReport`, first of all middleware). A request sent with `X-Raqam-Cache: off` is answered
from an empty cache of its own, so the number is what a first visit costs whatever the shared cache holds. Nothing is
left behind: that cache lives as long as the request, and the shared one is the default again afterwards.
`QUERY_REPORT_LOG` (a file) also writes every query with its time, one JSON line per answer.

`backend/scripts/qa.sh serve` always turns the count on; `RAQAM_QA_QUERY_LOG=<file>` adds the log. Production refuses
to start with either set (`ProductionChecks`). Queries made after the answer is sent (`defer`, terminate) are not in
the number.

### What is checked

- **The QA budget** (`e2e/qa/query-budget.spec.ts`, project `query-budget` of `playwright.qa.config.ts`): every GET
  endpoint of the API (74, with a post of each kind) and every screen of the panel (88) may make at most the number in
  `e2e/qa/query-budgets.json`. A screen is counted whole: its page and the requests its parts make after it (lazy
  widgets). A budget is named by its route, and a post's by its kind too, never by a record's id, so the budgets still
  apply after the QA database is built again. The check also fails for a budget left for an endpoint or screen that no
  longer exists. Each run writes its
  counts to `e2e/test-results/query-counts.jsonl`; after a deliberate change, `node qa/query-budgets.mjs` (from `e2e/`)
  writes them as the new budgets. A budget is never raised only to make the check pass.

- **The sample.** The API's post budgets count one post of each kind: the newest live one of the QA data, made with
  `raqam:demo --seed=2026 --posts=2000`. `e2e/qa/query-sample.json` records those posts and their parts, and the check
  stops if the data is not that sample. A count differs from kind to kind because the sample posts differ: the news
  (no related items, no blocks) is the plainest, 18; the opinion has 3 related items and a "read also" block, 22; the
  report has a gallery of 4 pictures, an awareness card in its text and a related item, 32. These are fixed costs of
  each part, not growth: the growth tests (`ApiQueryGrowthTest`) show that more pictures in a gallery, more related
  items, more awareness cards among them and more posts in a "read also" block add no query.

  A list of the panel costs one query more when it has rows than when it is empty (the page does not ask for the rows of
  an empty list): the notifications screen counts 12 with the QA admin's six notifications and 11 with none, the
  redirects list 12 with the demo's three redirects and 11 with none. The rows are read in one query, so the count does
  not grow with them. The redirect lookup's budget is counted on one of those redirects (a 301), not on a missing one.

  ```sh
  cd e2e && npx playwright test -c playwright.qa.config.ts --project=query-budget
  ```

  Not counted: `ads/click/...` (a visit counts a click) and `account/*` (customer accounts are switched off in V1).

- **The growth tests** (Pest, `tests/Feature/Performance/`): each list of the API and each screen of the panel is
  answered with a few items, then with more, and the two must cost the same number of queries (the home page: one more
  per block, its own posts). A query per item makes the second number larger. They run in CI with the rest of Pest.

### What was found and fixed

A query per item (N+1) in these lists, each first caught by a growth test that failed, then fixed, then the fix broken
on purpose to see the test fail again (54 such breaks, all caught):

| Where | Before | After |
|---|---:|---:|
| `GET /sitemaps/videos` (every video: its poster and chapters one by one) | 640 | 5 |
| `GET /home` (each block drew its posts alone; kinds and switches read per list) | 207 | 49 |
| `GET /awareness` (each card's character, pose and type one by one) | 100 | 12 |
| `GET /guests` (each guest's company, sector, city and links) | 68 | 9 |
| `GET /posts/{id}`, a round table (each guest the same way) | 55 | 26 |
| `GET /playlists/{slug}`, `/videos` (as the sitemap) | 42, 40 | 11, 5 |
| `GET /characters`, `/characters/{slug}/cards` (poses, counts and cards per character) | 33, 25 | 6, 9 |
| `GET /services`, `/advertise` (the booking switch, contact settings and currency per service) | 25, 29 | 11, 15 |
| `GET /ads` (each house banner's picture alone) | 24 | 8 |
| `GET /weather/widget` (each city's reading and an unused forecast) | 25 | 2 |
| `GET /playlists` (each playlist's section, season and count) | 14 | 8 |
| `GET /authors`, `/team` (each writer's links) | 12, 9 | 5, 4 |
| Panel: a picture's screen (the title of each post that uses it) | 96 | 36 |
| Panel: home page layout, menus (the choices of every block or item, again and again) | 53, 45 | 15, 21 |
| Panel: subscribers (three counts for each of twelve weeks) | 53 | 14 |
| Panel: characters, currencies, cities, organizations, services (a lookup per row) | 52, 42, 39, 34, 24 | 16, 21, 16, 16, 15 |
| Panel: posts and media lists (the thumbnail and the kind's name per row) | 36, 23 | 17, 14 |
| Panel: a playlist being edited, a post's gallery and guests (a label per item) | 38 | 18 |
| Panel: e-mail templates (22 inserts each time the list opened) | 34 | 13 |

Values read once for a whole answer are kept on the request (`App\Support\RequestMemo`, one key in
`request()->attributes`), never in a static property or on a long-lived object, so they end with the request even on a
server that keeps the application between requests. A queue worker runs all its jobs on one request object:
`Queue::before` empties the memo before each job (and only the memo), so no job sees what an earlier one read.
The admin team's permissions are kept the same way (`App\Access\Access`): the sidebar and the quick search ask whether
the admin may see each screen, and each answer costs one query per request, not one per question. A role given or
taken through the service, the `multi_admin` switch moved or a transaction rolled back forgets them at once.
(A test's application keeps its controllers from one request to the next: the growth tests forget them before each
count, as a new request on the server would.)

Left as they are: the dashboard (52) and the statistics screen (42) cost the same whatever the data holds (one query
per widget or table); the post editor reads its kind's facts a few times (39 to 46, the same whatever the post holds).

## The heaviest queries (EXPLAIN ANALYZE)

`backend/scripts/qa-explain.php` runs every distinct read query of a QA query log (516 after the budget run) three
times under `EXPLAIN (ANALYZE, BUFFERS)`, in a read-only transaction that is rolled back, keeps the best time, and plans
the slowest again with seq scans off: a table still read whole then has no index the query can use.

```sh
cd backend && APP_ENV=qa php scripts/qa-explain.php ~/raqam-qa/perf/queries.jsonl 15
```

The 15 slowest, on 2000 posts:

| # | ms | Query | Index |
|---:|---:|---|---|
| 1 | 31.2 | Panel, unanswered chatbot questions: how many times each was asked (a sub-query per row) | uses `bot_messages_unanswered_idx` |
| 2 | 2.9 | Pages sitemap: the tags with the newest post of each | yes |
| 3–4 | 2.4, 2.1 | `GET /tags`: the tags with live posts, counted | yes |
| 5 | 1.7 | Panel, audit log: the tables named in it (filter list) | `audit_log_subject_idx` |
| 6 | 1.7 | Posts sitemap | `posts_published_idx` |
| 7–8, 15 | 1.5, 1.4, 1.2 | Videos a visitor can play (list, home, count) | yes; the count reads `videos` whole (below) |
| 9–11 | 1.4, 1.4, 1.3 | The newest change of live posts (sitemaps), their count (list pages) | `posts_published_idx` |
| 12 | 1.3 | Panel, chatbot questions of 30 days, grouped | yes |
| 13 | 1.3 | Live posts per tag (a post's tags) | `post_tags_tag_idx` |
| 14 | 1.3 | Posts that use the videos (video sitemap) | yes |

**No index is missing.** With so few rows PostgreSQL reads most tables whole because that is cheaper; with seq scans
off every one of these queries finds an index, except the count of playable videos, which counts the whole table
(there is nothing to skip; the table holds hundreds of rows). No schema change was needed.

To watch as the data grows: query 1 counts, for each of the ten questions on a page, the unanswered messages with the
same normalised text (`normalize_ar` on every unanswered message, once per row shown): about 3 ms per row now, growing
with the unanswered messages kept (180 days). Storing the normalised key (or an index on it) would remove that, and is a
schema change.

## Load (k6)

k6 v2.4.0, the official release (`~/tools/k6-v2.4.0`, checked against the release's checksums file). The scripts are
`e2e/qa/load/api.js` and `e2e/qa/load/site.js`; each runs its four targets one after the other, at a constant rate
(requests started per second, whatever the answers' speed), for 30 s each.

```sh
cd e2e
~/tools/k6-v2.4.0/k6 run -e TOKEN=<API_SITE_TOKEN of backend/.env.qa> -e CACHE=on qa/load/api.js
~/tools/k6-v2.4.0/k6 run -e TOKEN=<API_SITE_TOKEN of backend/.env.qa> -e CACHE=off qa/load/api.js
~/tools/k6-v2.4.0/k6 run -e TOKEN=<…> -e CACHE=on qa/load/site.js    # the site started as usual
# then the site started with no room for pages: RAQAM_QA_PAGE_CACHE_MB=0.0001 backend/scripts/qa.sh site start
~/tools/k6-v2.4.0/k6 run -e TOKEN=<…> -e CACHE=off qa/load/site.js
```

### The API: 20 requests a second on each, with the site's token (goal: p95 under 300 ms)

"Without the cache" sends `X-Raqam-Cache: off`: every request is answered from an empty cache (the first visit's cost).

| Endpoint | With the cache: median / p95 / p99 | Without: median / p95 / p99 |
|---|---|---|
| `/home` | 130 / **230** / 262 ms | 162 / **255** / 277 ms |
| `/posts/{id}` (news) | 57 / **70** / 78 ms | 57 / **71** / 90 ms |
| `/search?q=الذهب` | 59 / **77** / 92 ms | 57 / **75** / 89 ms |
| `/currencies` | 30 / **36** / 39 ms | 38 / **46** / 51 ms |

No request failed. Before the home page's fix (124 queries, about 145 ms alone) the same load gave `/home` a p95 of
3.9 s with or without the cache: four PHP workers could not keep up with 20 a second, and the requests queued. At 49
queries (about 97 ms alone) it holds, close to the goal: `/home` is the one to watch. It has no cache of its own on the
back end; the website's page cache is what keeps it from being asked often.

### The website: 10 pages a second on each

"Without the cache": the site started with no room in its page cache (`RAQAM_QA_PAGE_CACHE_MB=0.0001`), so every visit
draws the page; the site's own data cache (`use cache`) still holds the API's answers.

| Page | With the page cache: median / p95 / p99 | Without: median / p95 / p99 |
|---|---|---|
| `/` | 9 / 11 / 12 ms | 165 / 303 / 342 ms |
| A news item | 6 / 8 / 9 ms | 233 / 428 / 471 ms |
| A category | 6 / 8 / 8 ms | 193 / 374 / 417 ms |
| `/search?q=الذهب` | 77 / 144 / 162 ms | 271 / 477 / 584 ms |

No request failed. The search page is drawn for each query in both (its results are not kept as a page).
