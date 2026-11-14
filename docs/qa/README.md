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
scripts/qa.sh serve                  # the API and the panel; the panel's address is ADMIN_PATH in .env.qa
scripts/qa.sh artisan <command>      # anything else, against raqam_qa
```

`setup` refuses if `.env.qa` or the database already exist. To start again, drop `raqam_qa` and delete `.env.qa` by
hand. The script never drops or empties anything itself.

Outside services are off in the QA settings: no YouTube, OpenWeather, Brevo, IndexNow or Turnstile key, and mail goes
to the log. The Bank of Israel needs no key, so the currency fetch reaches the real bank when it runs.

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
