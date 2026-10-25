# Visit statistics (decision 22)

Internal only: no Google Analytics, no cookie, so the site needs no cookie banner. Code: `backend/app/Analytics`.

## What the site reports

`POST /api/v1/stats/hits` (contract: `docs/api/openapi.yaml`). The browser never calls it: it calls a route of the
site on the same domain, and the site's server calls the API with `X-Raqam-Site-Token`, `X-Raqam-Visitor-Ip` and
`X-Raqam-Visitor-Ua`, as for ad views. JSON only, no CORS, no cookie; always `204` for a well-formed report, whether
it counted or not. A report without the token or a valid visitor address counts nothing.

| `type` | When | Fields |
|---|---|---|
| `view` | every page view | `path` (with its query), `referrer` (`document.referrer`), `post_id` on a post's page |
| `event` | an interaction (22.8) | `event`: `video_play` (+ `video_id`), `share_click`, `converter_use`, `newsletter_signup`, `chatbot_open`, `infographic_download` |
| `not_found` | the site answered 404 (after `/redirects/lookup`) | `path` |

The contact buttons of a service keep their own endpoint (`/services/{slug}/contact`, phase 7). Pages shown in
preview or draft mode are not reported; the panel's own preview (`/preview/…`) is never counted, and a post that is
not published (draft, scheduled, trash) gets no readings whatever is reported.

## Who a visitor is (22.2)

`VisitorDay`: an HMAC of the visitor's network (IPv4 address, or IPv6 /64) and browser, under a random salt made for
each Palestine day. The salt lives only in Redis and expires after two days, with every counter of that day: after
that nobody, not even with the database and the keys, can tell which address a visitor was. The address is never
stored, not even in a Redis key. Two browsers behind one address (a shared line) are two visitors.

## What counts

- Robots and search engines never (`App\Support\BotAgent`, 22.3).
- A page view: the same page (query ignored) again within 10 seconds counts once; at most 500 views per visitor a day.
- A visit, for sources and devices, opens with a visitor's first view after 30 minutes of quiet; its source is that
  view's `utm_source` tag, otherwise its referrer's host (Google, Bing, Facebook, Instagram, WhatsApp, TikTok,
  YouTube, X, five answer engines, other sites), otherwise direct (22.6). The answer engines (search assistants) are
  read before the search engines, since one of them lives on a search engine's subdomain; their hosts, tags and apps
  are listed in `App\Analytics\AnswerEngineSources` and their codes are rows of `traffic_sources`. A link from the site itself is direct. Newsletter links carry
  `utm_source=newsletter`. Device: phone, tablet or computer from the browser (an iPad that says it is a Mac counts
  as a computer, 22.7).
- A post's reading: once per visitor, post and day (22.4); archived posts still count at their address.
- An interaction: the same one again within 10 seconds counts once; at most 60 per visitor an hour. A video play
  needs a video the site shows and counts once per visitor, video and day.
- A missing page: recorded without its query, once per visitor, path and day, at most 30 paths per visitor a day,
  straight into `not_found_paths` (database clock).
- One network (an address, or an IPv6 /64) brings at most 300 visitors a day: a browser name is easy to change, so
  without it one address could invent visitors and readings. A shared line or a phone company's address carries many
  real people, hence the high ceiling; visitors already seen keep counting.
- The endpoint itself: 120 requests a minute per visitor.

All limits are in `config/raqam.php` (`stats`).

## From Redis to PostgreSQL (22.1)

Counters live in Redis on the queue connection (`REDIS_DB`; `cache:clear` never empties it), one set of keys per
Palestine day, holding the day's running totals. `stats:flush` (every hour at :05) writes today and yesterday into
`site_stats_daily`, `post_views_daily`, `traffic_sources_daily`, `device_stats_daily`, `event_stats_daily` and
`video_plays_daily`, in one transaction, each row as the larger of the stored and the counted figure, then refreshes
`post_view_totals`:

- run twice, or cut off and run again: the same numbers, nothing counted twice;
- Redis lost its data: no stored figure goes down (counts between the last save and the loss are lost; run Redis with
  `appendonly yes` so that is at most a second);
- rows of a post, video, source or event that no longer exists are left out.

## Most read and view counts

`GET /api/v1/posts/most-read?period=day|week&limit=1..12` (22.5): live posts by readings, today (yesterday just after
midnight, `meta` says which) or the last seven days. Kept until the next transfer. View counts are hidden from
visitors (22.12): the API sends `views` (all time) and `period_views` only while the switch «إظهار عدد المشاهدات
للزوار» (`public_view_counts`) is on.

## Retention (22.13)

Daily figures are kept for ever. The raw counters expire in Redis within two days; `stats:prune` (every night at 02:50)
deletes missing pages nobody reached, or resolved, more than `analytics.raw_retention_days` days ago (90).

## The panel

- **الإحصائيات → الإحصائيات**: a period (today, 7, 30 days or picked), totals with the change from the period before,
  a day-by-day chart drawn on the server (bars: page views, line: visitors; also as a table), sources, devices,
  interactions (with the services' contact buttons and the most played videos), the most read, readings by category,
  special section and author (22.4), and the other modules over the same days: search, ads, newsletter, chatbot,
  contact messages (22.9). Today is live (Redis), earlier days as stored. **Excel export** (one sheet per table, text
  cells only, written to the audit log). Nothing polls: the page changes only when another period is picked.
- **الإحصائيات → صفحات غير موجودة (404)**: open paths, most reached first; «اعمل تحويل» opens the redirects screen
  with the old address filled in, and any redirect from that address (made by the admin or by the application) marks
  it handled; «تمت المعالجة» / «إعادة فتح», several at once. A handled path that is still reached says so.
- The home shows today's visits (live) and the most read today; the weekly summary carries the week's visits, top
  sources and most read.
