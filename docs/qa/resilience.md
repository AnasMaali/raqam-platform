# Failures, a day of the scheduler, backups (QA-6)

What happens when a part of the platform or an outside service stops, measured on the QA environment (`raqam_qa`,
[README.md](README.md)); one whole day of the scheduler; and a backup restored. For each part stopped, three questions:
the website stays up (no server error reaches a visitor), the admins are told, and everything comes back by itself when
the part does.

## How a part is stopped

Never by stopping the machine's own services (Redis, PostgreSQL) and never by emptying anything. A second QA API is
started beside the first, on another port, with one setting of `.env.qa` replaced in its environment (Laravel keeps a
value already in the environment over the file's):

- an address where nothing listens (`REDIS_PORT=6399`, `MEILISEARCH_HOST=http://127.0.0.1:7799`,
  `MAIL_PORT=2599`): the connection is refused at once;
- an address that never answers (`REDIS_HOST=10.255.255.1`, the same for Meilisearch and the mail server): the caller
  waits for its own timeout;
- the outside services through `e2e/qa/stand-in.mjs`, a local stand-in for the Bank of Israel, OpenWeather, YouTube,
  Brevo and Turnstile. Each can be told to answer 503 or to never answer (`POST /_control {"boi": "error"}`). It is
  also an HTTP proxy that refuses everything: with `HTTPS_PROXY`/`HTTP_PROXY` pointing at it, any request to a real
  host is refused and its host recorded.

```sh
cd e2e && node qa/stand-in.mjs 8790
# then, for a QA command: CURRENCY_BOI_BASE=http://127.0.0.1:8790/boi OPENWEATHER_API_BASE=http://127.0.0.1:8790/ow
#   OPENWEATHER_API_KEY=<anything> YOUTUBE_API_BASE=http://127.0.0.1:8790/youtube YOUTUBE_API_KEY=<anything>
#   BREVO_API_BASE=http://127.0.0.1:8790/brevo/v3 BREVO_API_KEY=<anything> BREVO_LIST_ID=3
#   TURNSTILE_VERIFY_URL=http://127.0.0.1:8790/turnstile/siteverify
#   HTTPS_PROXY=http://127.0.0.1:8790 HTTP_PROXY=http://127.0.0.1:8790 NO_PROXY=127.0.0.1,localhost
```

The queue and the scheduler are stopped by not running them; the API, from the website's side, by stopping the QA API
or by a server that accepts the request and never answers.

## What was found, and what each part does now

Each fault was first caught by a test that failed, then fixed, then the fix was broken on purpose to see the test fail
again (34 such breaks, 32 in the back end and 2 in the website, all caught). The tests: `tests/Feature/Resilience/` (Pest) and the hanging platform in
`frontend/tests/e2e/article.spec.ts`.

| Part stopped | Before | Now |
|---|---|---|
| Redis, refused | every answer of the API was a 500, the panel too, and `/up` said all was well; no alert | the API's reads are answered from the database (200, 40–260 ms); the forms answer 503 `unavailable` before writing anything; the panel shows a plain 503 page; `/up` 503; one alert |
| Redis, not answering | every request waited PHP's 60 s, then a 500 | about 1 s (connect timeout 1 s, read 2 s), then as above |
| Meilisearch | the search went to the database (as designed); no alert | the same, and one alert |
| Mail server, refused | 3 tries, the message `failed`, the bell rings (the job's failure) | unchanged |
| Mail server, not answering | 60 s a try: a sign-in code (sent at once, 3 tries) held a PHP process for 3 minutes | 10 s a try (31 s for the 3) |
| Bank of Israel, OpenWeather, YouTube, Brevo (503, or no answer) | 10 s a try, 2 tries; the last good data stays on the site; at the third failure in a row one alert; the next success clears it | unchanged |
| The queue worker | jobs waited without anyone knowing | a job waiting 10 minutes raises an alert (`system:watch`, every minute); `/up` 503; a worker started again empties the queue |
| The scheduler | nothing noticed it | the queue worker looks every 5 minutes, `/up` at every call: one alert ("not running" or "stopped N minutes ago"); its next minute puts everything right |
| The API, stopped (website's side) | pages in the page cache served (200, a few ms); others a tidy page in the site's frame | the same; the post page that says it could not load is also `noindex` |
| The API, not answering | the visitor waited about 54 s, then a bare "Internal Server Error" | the tidy page in 5 to 15 s: every request of the site gives up after 5 s |

### Redis

`App\Http\Middleware\RedisOutage` (first of all middleware) asks Redis once per request, when the cache, the sessions
or the queue are kept there:

- **The API's reads** (GET, HEAD) go on with a cache of their own request, request limits included. They read the
  database directly; the home page with its 49 queries answered in 260 ms.
- **The forms** (every other API request: contact, newsletter, chat, the counts) answer 503 at once. Their limits,
  codes and mails live in Redis: answering without them would let a form be sent without limit, and a message be kept
  without its mail.
- **The panel** answers a plain page in Arabic (`resources/views/errors/503.blade.php`) as soon as its session is asked
  for. A Redis that stops halfway through a request ends the same way (bootstrap/app.php), never as a 500.
- **An ad click** still takes the visitor to the advertiser, but is not counted: without Redis the gap between two
  clicks cannot be kept, and each click would count.

When Redis is back, the next request finds it: nothing to restart.

What the outage costs afterwards: values a scheduled task changed while Redis was away (a currency fetched, the
weather) were cached for that request only. Redis still holds the boards of before, until their own lifetime ends (at
most an hour).

### Alerts

`App\Panel\SystemWatch` raises them, each at most once an hour (`AdminAlerts`), in the bell and by an e-mail sent at
once, never queued: the queue may be the part that stopped (the template `admin_service_down`). Who watches whom:

- every request watches Redis;
- the scheduler (`system:watch`, every minute) beats and watches the queue;
- the queue worker, between its jobs, watches the beat;
- `/up` watches all four and says which one fails (database, redis, scheduler, queue; `true` or `false`, nothing else).
  It is for a monitor outside the server (Uptime Kuma, decision 26). It is also the only watcher left when the
  scheduler and the worker have both stopped, and it raises the alerts too.

A watcher found one fault of its own during this work: work deferred after the answer (`defer()`) is skipped when the
answer is an error (400 or more), unless it is marked `always()`. The alert of a 503 never went out; the tests ran
deferred work at once, and missed it until they ran it as the server does.

## A day of the scheduler

`backend/scripts/qa-schedule-day.php` runs every task of the schedule in the order, and at the minutes, the schedule
gives them on one day (Palestine time). Each runs in its own process, as the scheduler runs it. The schedule is built
again for every minute, as each run of `schedule:run` builds it: a time window (`between()`) is fixed when it is
built. The tasks of every minute run every 60th minute; the weekly tasks once after the day. It refuses to start unless
the mail goes to the log and every outside address is local.

```sh
cd e2e && node qa/stand-in.mjs 8790 &
cd ../backend && env <the stand-in settings above> APP_ENV=qa php scripts/qa-schedule-day.php 2026-11-18
```

2026-11-18 (a Wednesday), on the 2000 posts of the demo:

- **The run:** 22 tasks, 502 runs, 156 s.
- **Errors:** no task failed, no job failed, no alert of a failed task or job, and not one error written to the log.
- **The currency fetch:** ran at 12:00 and every half hour to 17:30 (12 times).
- **The weekly tasks:** `videos:check-availability` and `admin:weekly-digest` ran once.
- **Outside requests:** none reached a real host. The stand-in had 12 from the bank and 1 from YouTube; the proxy
  refused none.
- **Mail:** the weekly summary went to the mail log.
- **The queue:** the jobs the day queued (the webhooks to the site, the e-mails) were then taken by a worker; none
  failed.

## Backups and restoring

`pg_dump` of `raqam_qa`, restored into a new database, `raqam_qa_restore` (never over a database in use):

| Step | Result |
|---|---|
| `raqam_qa` | 44 MB (2000 posts of the demo, 108,319 rows in all), media 30 MB beside it |
| `pg_dump -Fc` | 0.7 s, 1.9 MB |
| `createdb` + `pg_restore -j 4 --exit-on-error --no-owner` | 7.2 s, no error; 38 MB (the source's space left by deleted rows is not copied) |
| `scripts/check-restore.sh raqam_qa raqam_qa_restore` (9.5 s) | the same schema (132 tables, 45 triggers, 19 functions); the same rows in every one of the 133 tables and views (count and checksum); the same 58 sequences; the materialized view filled alike |
| The API on the restored database | the same answers, byte for byte, as on `raqam_qa`: the site, the home page, a post, the list, the currencies, the weather, the categories, the posts sitemap, a search. A form writes (the next id after the restored ones). |
| `scripts/restore-probes.sql` | 12 of 12: domains, checks, keys, the audit trail append-only, new ids after the restored ones, `normalize_ar`, the view counts refresh. All in one transaction rolled back. |
| `database/tests/normalizer_parity.py raqam_qa_restore` | 18 of 18 |

`database/tests/schema_tests.sql` needs an empty database (its rows collide with the demo's), so the probes stand in
for it on a full one. `check-restore.sh` was itself broken on purpose three ways: a row changed, a sequence moved on,
a check dropped. It found each one, and found the copy the same again once each was put back.

PostgreSQL prints a list check (`status IN ('a', 'b')`) of a schema built from SQL text one way, and the same check
read back from a dump another way. The two mean the same; the comparison writes both alike.

The steps for production are in [docs/deployment-requirements.md](../deployment-requirements.md#backups-and-restoring).
