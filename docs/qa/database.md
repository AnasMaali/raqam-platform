# The database (QA-7)

The schema's rules, the demo data held against them, locks under load and at the same moment, Arabic order, time
zones, the application's role, size and growth, and every migration rolled back with data. Measured on the QA
environment (`raqam_qa`, [README.md](README.md)) and on copies of it (`raqam_qa_*`). `raqam` is never touched;
`raqam_testing` only through Pest.

## Every rule has a test that breaks it

The schema enforces:
- 217 CHECKs;
- 8 domain CHECKs;
- 1 EXCLUDE;
- 16 trigger functions on 46 triggers.

The SQL suite (`database/run_tests.sh`) runs `database/tests/schema_tests.sql`, then one file per schema file
(`database/tests/rules/NN-*.sql`), then `database/tests/coverage.sql`, in one session.
- `pg_temp.expect_fail(label, statement, rule)` records which rule refused the statement:
  - the error's constraint name;
  - or, for an error a trigger raised, the trigger function's name.
- It passes only when that very rule refused it. A statement refused by a NOT NULL, a key or another CHECK fails the
  test. So a test reaches its rule and nothing else.
- A trigger that does something (a number, a history row, `updated_at`) rather than refuse is checked by its effect,
  with `expect_trigger`.
- `coverage.sql` lists every rule from the catalog and fails the suite for any rule no test broke.

| | Rules | Broken by a test | Through a stand-in |
|---|---|---|---|
| CHECK | 217 | 214 | 3 |
| domain | 8 | 8 | 0 |
| EXCLUDE | 1 | 1 | 0 |
| trigger function | 16 | 16 | 0 |

The full table, rule by rule with its tests, is [database-coverage.md](database-coverage.md), made by
`database/tests/coverage-table.sh`.

**Stand-ins.** Three rules can never be the one that refuses, because another rule always refuses the same rows first.
`settings_check2`, `settings_check5` and `settings_check6` cast a url, e-mail or phone setting to `url_text`,
`email_address` and `phone_e164`. A bad value fails the cast (the domain refuses it) before the CHECK can be false, so
each counts through its domain, with the reason written in `coverage.sql`.

**Mutation sweep.** Each of the 242 rules was dropped in turn on a fresh copy of the schema, and the whole suite run:
- 242 of 242 made it fail.
- The first sweep, before each test named its rule, missed 14. Their statements were also refused by something else:
  - the subtype rows pointed at a post of another kind, so the composite key refused them too. Now they point at a post
    of the kind they claim, and only the CHECK stands between, say, a podcast episode row and a success story's post;
  - a zero buy rate also met the EXCLUDE of an existing override;
  - two CHECKs were implied by others, so dropping them changed nothing. They are gone now (decision QA-7):
    `page_views >= 0` follows from the rules on `unique_visitors`, and `searches >= 0` from those on
    `zero_result_searches`. The tests of a negative count are named after the rule that refuses it now.

Defects found in the tests themselves:
- **`updated_at refreshed by trigger`** compared `updated_at >= created_at`. That holds with or without the trigger:
  the run with every `*_set_updated_at` trigger dropped still passed. The row now starts a day old, and only the
  trigger can bring it back to the present.
- **The two "published post without its subtype row" tests** counted the error of `SET CONSTRAINTS` (the trigger was
  missing) as the trigger's refusal. They are now named after the trigger, and fail without it.

**The decisions of QA-7**, each with its test first and a mutation:
- **NaN.** A `numeric` column takes `'NaN'`, and PostgreSQL counts NaN as larger than any number, so a CHECK of
  `> 0` let it through. The fix is `col > 0 AND col < 'Infinity'` (NaN is not below Infinity) on:
  - exchange rates, pegs and overrides;
  - invoice quantities;
  - media durations;
  - wind speeds.

  Forecasts get the observations' range (-60 to 70 °C). Infinity does not fit those columns' precision, so the column
  itself already refuses it; the tests show both.
- **The url setting** is read as `url_text`, so `'https:// not a url'` is refused.
- **The two implied CHECKs** are dropped.

## Integrity of the demo data

`backend/scripts/qa-integrity.php` runs one READ ONLY, REPEATABLE READ transaction (one snapshot, one `now()`) and
rolls it back. It checks:
- **The catalog:** no constraint left NOT VALID, no trigger switched off.
- **Keys:** every one of the 196 foreign keys looked up again, row by row (no orphan).
- **CHECKs and domains:** every CHECK and every domain CHECK evaluated again on every row.
- **What the triggers enforce on each write, held on the rows there now:**
  - a booking's status is its last history row's;
  - each history step is an allowed transition;
  - at most 3 attachments per booking;
  - refunds within their payment;
  - categories one level deep;
  - no own rates for a pegged currency;
  - dependencies of enabled features;
  - roles for staff only;
  - a category, and the subtype row, for a section post outside draft;
  - no published post in the future.
- **Generated columns, counted again in PHP:** the search columns (with `ArabicNormalizer`), the booking references and
  the invoice numbers.
- **Views, counted again in PHP from the rows they read:**
  - `post_view_totals`: the daily views added up;
  - `invoice_totals`: lines and tax, rounded half away from zero as PostgreSQL rounds;
  - `effective_exchange_rates` and `rate_board`: the latest rate (a pegged one from its anchor), the spreads or the
    override in force, and the change against the last rate of an earlier Palestine day;
  - `live_ad_campaigns`;
  - `video_usage`.

The demo leaves bookings, payments and invoices empty (those systems are dormant in V1).
`backend/scripts/qa-commerce-sample.sql` fills a copy with them through the database's own rules:
- 300 bookings walked through their statuses (749 history rows);
- 99 attachments;
- 50 payments, 20 refunds, 39 invoices with 78 lines.

| Database | Checks | Off |
|---|---|---|
| `raqam_qa` (2008 posts, 29877 daily view rows, 1310 rates) | 24 | 0 |
| copy + commerce sample (300 bookings, 50 payments, 39 invoices) | 24 | 0 |

**Mutations.** On copies, each of 13 spoilings was caught: an orphan behind a NOT VALID key, a bad row behind a NOT
VALID CHECK, a bad e-mail behind a NOT VALID domain, a refund over its payment with the limit switched off, a status
written past its history, a fourth attachment, a published episode without its row, daily views changed after the last
refresh, and five changed views (tax cut instead of rounded, a doubled spread, the previous day taken by UTC, an
ended campaign kept, the bin's body videos kept). The view mutations come with the rows that make them visible: an
ended campaign, a rate just after midnight in Palestine (still the day before in UTC), a binned post with a video.

## Refreshing the view totals under load

`stats:flush` runs `REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals` every hour, outside its write
transaction. `backend/scripts/qa-refresh-load.sh` refreshes it again and again on a copy with a year of daily views
(293857 rows). Meanwhile, with pgbench:
- 8 readers ask what the API asks (the most-read list, one post's total);
- 2 writers run the flush's upsert.

Every 100 ms the reader and writer sessions waiting on a lock are counted.

| Refresh | Seconds | Refreshes (longest) | Readers | Writers | Samples with a session waiting on a lock |
|---|---|---|---|---|---|
| `CONCURRENTLY` (the platform's) | 60 | 155 (543 ms) | 4738 a second, 1.7 ms | 179 a second, 11.2 ms | **0 of 231** |
| plain (to show the measure works) | 30 | 130 (417 ms) | 3867 a second, 2.1 ms | 178 a second, 11.2 ms | 89 of 138 (up to 8 at once) |

The site never waits for the refresh. Only the demo's own seeding and removal (development only) refresh without
`CONCURRENTLY`.

## The same row at the same moment

`backend/tests/Feature/Concurrency/SameMomentTest.php` runs each case in real child processes (`pcntl_fork`):
- each child opens its own connection;
- they all wait at a gate, an advisory lock the test holds, and the test opens it only when every one of them is
  waiting;
- the rows are committed, so this file rebuilds the test database itself instead of using a transaction.

| Case | At once | Must happen |
|---|---|---|
| refunds of one payment (12345, 5000 each) | 5 | 2 go through, 3 are refused cleanly (`PaymentRefused`, never a database error); 10000 refunded |
| the bank's answer to one payment | 3 | captured once, the booking moved to "paid" once, one invoice |
| admins moving one booking to the same stage | 5 | the customer told once |
| editors saving one article with the version they opened | 2 | one saved, the other told |
| tabs autosaving one draft from the version they opened | 2 | one kept, the other told |
| invoices issued for 8 payments | 8 | numbered 1 to 8, none twice, none skipped |

**Defect found:** the overwrite guard (`PostSaver::save` and `autosave`) compared the post's time before the save's
transaction, with no lock. Two editors who opened the same version and pressed save at the same moment both passed,
and the second silently overwrote the first. That is the very case the guard is there for.
- The two tests failed (both saved).
- The fix: the guard now locks the row and compares inside the save's transaction (the autosave got one), so the
  second waits, sees the first one's time, and is told.

**Mutations:** removing each lock, or moving the guard back out of the transaction, fails its case (7 of 7):
- the refunds' `FOR UPDATE`;
- the attempt's and the booking's in `Payments::sync`;
- the booking's in `BookingStatuses`;
- the post's;
- the guard back outside the save's transaction, and outside the autosave's;
- document numbers that read the counter, then write it.

## Arabic order

Lists of names come back in the database's order: the API's authors and guests, the panel's pickers and tables, about
25 places. Neither of the platform's databases ordered them the way an Arabic reader would:
- the local ones are made with the C locale, which orders by code point;
- CI's `postgres:16` image makes them with `en_US.utf8`.

Both put «أحمد» before «إبراهيم»: every hamza form of the alef counted as its own letter.

The fix (decision QA-7): the 11 columns lists are ordered by carry `COLLATE "ar-x-icu"` themselves, so the order no
longer depends on how the database was made. Slugs, codes and e-mails do not, and neither does the database.
`ArabicCollationTest`:
- asks the API for twelve names and expects ICU's order, checked on PostgreSQL 16 before it was written (آمنة, then
  إبراهيم, then أحمد);
- requires exactly those 11 columns to carry the collation.

ICU is now a deployment requirement, with the REINDEX to run after an ICU upgrade ([deployment-requirements.md, "PostgreSQL
with ICU"](../deployment-requirements.md#postgresql-with-icu-and-arabic-names-in-order)).

## Time zones

`backend/tests/Feature/Schema/TimeColumnsTest.php`:
- **No zoneless moments:** no column is `timestamp` or `time` without a zone; any added one needs its reason written
  in the test.
- **Plain dates, each with its reason:** there are 13, all Palestine calendar days (the day a count, a forecast, a
  meeting or a rate belongs to).
- **The session speaks UTC:** the connection sets it (`config/database.php`), and the local server's own zone is
  Asia/Jerusalem.

## A whole index on every foreign key

Five foreign keys had only a partial index (`WHERE deleted_at IS NULL`, `WHERE read_at IS NULL`...). A partial index
does not serve the key's own look-ups, made when a parent row is deleted or its key changes.

What was done (decision QA-7):
- **The test** (`AutomationSchemaTest`) asks for a whole, valid index.
- **The safety net** in `11-triggers-updated-at.sql` makes an index for any key without one. It too counts only whole
  ones now.
- **Each of the five has its whole index.**

Each partial index was kept only where a plan uses it. Measured on raqam_qa's index statistics from the QA crawls,
and on a copy grown to 37k posts, 50k bookings and 50k notifications:

| Partial index | Used | Kept |
|---|---|---|
| `posts_kind_published_idx` | 24 033 scans; the kind lists | yes |
| `posts_category_published_idx` | 0 scans; the category list uses `posts_published_idx` even at 37k posts | **no, dropped** |
| `services_group_idx` | 1031 scans | yes |
| `bookings_status_idx` | the inbox by status at 50k bookings | yes |
| `admin_notifications_unread_idx` | the unread count and list | yes |

## The application's role

[deployment-requirements.md, "The database roles"](../deployment-requirements.md#the-database-roles):
- the owner and the application;
- `database/roles/production-roles.sql`;
- `database/roles/check-roles.sh`: 40 of 40. The application refreshes the view totals through the owner's
  `refresh_post_view_totals()` (decision QA-7), and still cannot refresh the view itself, drop it or replace the
  function.

The check ran on a server of its own (a scratch cluster, then CI). Signed in as the application role on a copy of the
QA data:
- all 52 API endpoints answered;
- every scheduled command ran, `stats:flush` included once it called the function.

**Mutations:** each weakened grant was caught:
- the audit log made updatable;
- invoices and history made writable;
- TRUNCATE given;
- the migrations list made writable;
- the PDF column's grant left out;
- views made writable;
- a sequence right given;
- the function's grant left out;
- the function made runnable by PUBLIC;
- the function without SECURITY DEFINER.

## Size and growth

The demo holds one year of publishing (2008 posts over 366 days) and 93 days of visitors' activity. From the size of a
row in `raqam_qa` and the rows a year adds:
- the activity tables at their 93-day rate;
- the posts as they are;
- the audit log at about three saves a post.

The figures are upper bounds: small tables carry their pages' and indexes' fixed cost.

| Table | Rows a year | Size a year | Kept |
|---|---|---|---|
| `post_views_daily` | ~117 000 (up to ~294 000 when every published post is read every day) | ~12 MB (≤ 25 MB) | every row (the statistics by period) |
| `weather_observations` | ~22 700 | ≤ 1 MB kept (30 days) | 30 days (only today's are read) |
| `posts` | ~2 000 | ~10 MB | binned ones purged after 90 days |
| `post_revisions` | ~4 000 | ~4 MB | the last 100 of each post |
| `audit_log` + `audit_log_changes` | ~6 000 + ~22 000 | ~4 MB | 2 years |
| `bot_messages` | ~7 900 | ~2 MB | 180 days |
| the other daily statistics | ~35 000 | ~5 MB | every row |
| everything else | | ~3 MB | |

About 40 MB a year at most. Today's 47 MB would be about 90 MB after the first year: no concern for one server. The
media files are on their disk or R2, not in the database.

`backend/tests/Feature/Schema/TableGrowthTest.php` puts every table into one of three groups:
- **Cleaned:** 15 tables, each with its scheduled command or its cap, and the file that does it (its query on the
  table, not just its name).
- **Kept, with the reason written:** 27 tables.
- **People's:** 89 tables, which grow only with what editors, admins, customers or the reference data add.

A new table must be placed. Mutations caught: a cleaning unscheduled, a new table unplaced, a cascade the cleaning
relies on removed, a cleaning's query gone.

`retention:prune` also cleans two more tables (decision QA-7):
- weather readings after 30 days;
- an admin's notifications 90 days after they were read. An unread one is never deleted automatically.

## Rolling back with data

`backend/scripts/qa-rollback-data.sh` works on fresh copies of `raqam_qa` (131 tables, 107465 rows).
`scripts/check-schema-parity.sh` already does the same on an empty database.

1. **The whole stack, k = 1 to 13.**
   - Each rollback is refused at the first step, the seed of reference rows (migration 12).
   - Its `down()` deletes weather conditions, settings and the like that the data still uses (for example
     `weather_observations_condition_code_fkey`).
   - Each copy was left exactly as it was: same schema, same rows (the migration runs in a transaction).
   - Taking the reference rows back while the data uses them should be refused, so this is the expected answer.
2. **Below the seed, k = 1 to 12.** With the seed taken off Laravel's list (its rows kept), each of the 12 migrations
   under it went down with the data in place:
   - the tables it owns went with their rows;
   - the others kept every row;
   - `migrate` brought back exactly the same schema.

**Mutation:** a `down()` that forgets one of its tables fails from that migration down.
