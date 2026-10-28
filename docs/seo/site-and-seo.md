# Site settings, menus, sitemaps, feeds and IndexNow

What the website needs from the back end to build every page and to be found (decisions 21.12, 21.13, 23.10–23.12, 24.10).
The contract is `docs/api/openapi.yaml`; this page explains the rules behind it.

## Public addresses

`App\Seo\SitePaths` gives every non-post page one address, used by the menus, the sitemaps and the feeds. Posts keep
`App\Content\PostPath` (`/news/{id}/{slug}`, section kinds under their section).

| Thing | Address |
|---|---|
| Category | `/category/{slug}` |
| Tag | `/tag/{slug}` |
| Author | `/author/{slug}` |
| Guest | `/guest/{slug}` |
| Section | `/{slug}` (`/podcast`, `/success-stories`, …) |
| Static page | `/{slug}` (reserved words can never be a page's slug) |
| Character | `/awareness/characters/{slug}` |
| Playlist | `/video/playlists/{slug}` |
| Video | `/video/{id}` |
| Service | `/services/{slug}` |
| City | `/weather/{slug}` |
| Currency | `/currencies/{code}` (ISO code in lower case: `/currencies/usd`) |

## Old addresses of every page (23.6)

Posts keep their 301/410 as before. Every other page addressed by a slug (category, tag, author, guest, character, service,
page, playlist) uses `App\Content\KeepsOldAddresses`: a changed slug leaves a 301 from the old address (no chains), a
deleted record a 410 for its address and the old ones, and a record made again (or restored) at the same address clears
its 410. The site asks `/redirects/lookup` for any address it does not know.

## Lists the site draws without opening every item

* `/posts?initiative_status=ongoing|ended` (5.5) and `/posts?section=history&order=timeline` (5.4: era, then year, oldest
  first).
* Every list item carries `facts`: a history item's era and years, an initiative's name and state, an episode's season and
  number, a success story's company, person and figures, a product and its maker, a round table's date, an awareness card's
  type, character and pose (null for news, reports, opinion and infographics). They are read in one query per kind on the
  page, never one per item.
* The section filters (5.9, first version):
  * podcast: `season` (its number);
  * success stories and Made in Nablus: `sector` (its slug);
  * history: `era` (its id, from `/eras`);
  * initiatives: `initiative_status`;
  * awareness: `character` (its slug);
  * the round table has no filter, and nothing filters by city.

  `/sections/{slug}` lists each filter's values that have a live item, with their counts. A filtered page is noindex,
  with the section page as its canonical.
* `kind` takes several kinds separated by commas: `/news` is `kind=news,report,opinion,infographic`.
* Every item's `kind.schema_type` is the schema.org type of its JSON-LD (23.9).
* `/team` (28): the writers on the team page, in the admin's order, on one page.
* A company (`organization`) carries its sector, city, founding year and logo (5.2, 5.3), kept on «الجهات والشركات» with
  the sectors on «القطاعات الاقتصادية».
* `/site` carries `contact.map_url`, the place on a map for the contact page (28).

## `/site` and `/menus`

* `/site`: name (falls back to «رَقَم»), logo, contact details with a ready WhatsApp link, the visible social accounts, the
  default SEO and the switches the site acts on (`chatbot`, `ad_popup`, `ad_mobile_sticky`, `booking`, `online_payments`,
  `customer_accounts`, `public_view_counts`). Never: keys, internal mail addresses, schedules, panel-only switches.
* `/menus`: `header`, `footer_main`, `footer_legal`. A link is left out when the admin hid it or when its page is
  unpublished or its category or section is hidden; a top-level link left out takes its children with it.
* Webhooks (`App\Jobs\SendSiteWebhook`, after the save):
  * `{"type": "site", "change": "settings"}` when the name, logo, contact details, social accounts or default SEO change;
  * `{"type": "site", "change": "feature"}` when one of the switches above changes;
  * `{"type": "menus", "change": "<menu key>"}` when a menu is saved with a real change;
  * `{"type": "menus", "change": "target"}` when a linked page changes its slug or is published/unpublished, a linked
    category changes its slug or is hidden or deleted, or a linked section is hidden or shown.

## Sitemaps (23.10)

`App\Seo\SitemapBoard`. Only what a visitor can open and a search engine may index: live posts (`Post::live()`: no draft,
scheduled, archived, trashed or hidden-character card) and no row whose SEO says `noindex` or names its own canonical
address. The site writes the XML.

* `/sitemaps`: the index (pages, one entry per 2000 posts, news, videos).
* `/sitemaps/posts?page=N`: posts by id, so a page keeps its addresses as new posts arrive.
* `/sitemaps/news`: news, reports, opinion and infographics of the last 48 hours (Google News; at most 1000), with tags.
* `/sitemaps/pages`: home, sections, categories, pages, authors, guests, characters, playlists, tags with a live item,
  services, currencies and each currency, weather and each city, the video library.
* `/sitemaps/videos`: unchanged (phase 4).

## Feeds (23.12)

`App\Seo\FeedBoard`: the 30 latest live items for the site (`/feeds/posts`), a category with its sub-categories
(`/feeds/categories/{slug}`) or a section (`/feeds/sections/{slug}`); a hidden or unknown one is a 404.

## IndexNow (23.11)

* `INDEXNOW_KEY` in `.env` (8–128 letters, digits or dashes; production refuses anything else). Empty = nothing is sent.
  The key is not a secret by nature, but it stays out of the repository. **The website serves `/{key}.txt` with the key as
  its only content, from its own environment with the same value.**
* `App\Listeners\NotifyIndexNowOfPostChange` (on `PostChanged`, never for drafts) and the `Page` model queue
  `App\Jobs\SubmitIndexNow` **after the commit**: the post's address and, right after a slug change, the old address too;
  a static page when it is published, edited, re-addressed, unpublished or deleted. Noindex items are not sent.
* The job sends `host`, `key`, `keyLocation` and the full, encoded addresses to `https://api.indexnow.org/indexnow`. The
  same address is not sent twice within 60 seconds. A refusal that retrying cannot fix (400/403/422) fails at once and the
  admins see it in the bell; 429 and 5xx are tried again (3 tries).
* `INDEXNOW_ENDPOINT` is for browser tests only; production refuses to start with it.

## Answer engines (GEO)

* **Sources of visits**: five answer engines (search assistants) are sources of their own in the statistics, told by
  their referrer, their `utm_source` tag or their Android app (`App\Analytics\AnswerEngineSources`, read before the
  search engines). Their codes are seeded in `traffic_sources`.
* **Crawler policy** (`/robots`, `App\Seo\RobotsPolicy`, lists in `App\Seo\CrawlerAgents`): search engines always
  (`*`); answer engines allowed unless the admin turns them off (`seo.answer_bots`, on by default); crawlers that train
  language models closed unless the admin turns them on (`seo.training_bots`, off by default; the decision is the
  platform's). The pages of 23.17 (`/search`, `/newsletter`, `/bookings`, `/account`) and previews are closed to all.
  The site writes one robots.txt group per entry (`Disallow: /` for a closed group, the private paths for an open one).
* **llms.txt** (`/llms`, `App\Seo\LlmsBoard`): the name, a paragraph (`seo.llms_summary`, otherwise the default
  description) and links to the visible sections, the news categories, the published "who we are" pages (about, team,
  editorial, corrections and sponsored-content policies, contact, advertise, services) and the live data pages.
* A change of any of the three settings sends `{"type": "site", "change": "robots"}`: refresh `/robots.txt` and `/llms.txt`.
* Names of the engines and crawlers live only in their two data files, the seed and their tests (decision of 2026-10-25).

## The honeypot (24.10)

`App\Support\Honeypot`: the hidden field `website` on every public form: the contact form, the newsletter (subscribe and
code), the chat, booking and the customer-account forms (register, code, sign-in, forgot, reset). A filled field gets the
answer a person would get with nothing behind it: a challenge that leads nowhere, or the refusal of a wrong code or
password; nothing is stored, sent or counted. `HoneypotTest` fails when a new route that writes is neither a form with the
field nor named as "not a form".
