# Website page inventory

Every public address of the website, where its data comes from, and in what order it is built. The API contract is
`docs/api/openapi.yaml` (all paths below are under `/api/v1`); the addresses follow `App\Seo\SitePaths` and
`App\Content\PostPath` (see `docs/seo/site-and-seo.md`).

**Priorities**

- **P0**: needed at launch and built first. These are the shared templates and the pages most visitors and search
  engines reach.
- **P1**: needed at launch, built after P0. Most of them reuse P0 templates.
- **later**: after launch, or waiting for a decision.

**Rendering**

- Every page is rendered on the server and cached (Cache Components). The site webhook (`type: post`, `home`, `ads`,
  `site`, `menus`, …) clears the matching cache tags, so a change shows within seconds (21.16).
- Pages marked *dynamic* render per request.
- Pages marked *noindex* are closed to search engines (23.17).

The browser never calls the API. Anything that happens after the page loads goes through a route of the website's own
server, which adds the site token and the visitor's address. This covers instant search, forms, ad views and clicks,
service contact clicks, page views and 404 reports.

## Pages on every screen (layout)

| Part | Data | Priority | Notes |
|---|---|---|---|
| Header: logo, main menu, search entry | `/site`, `/menus` (`header`) | P0 | Needs a logo version without the slogan (see "For the design foundations"). |
| Breaking-news bar | `/posts?breaking=1` | P0 | Shown only while an item's `breaking_until` is in the future. |
| Currency strip | `/currencies/ticker` | P0 | Rates are indicative (11.10). |
| Weather widget | `/weather/widget` | P0 | The visitor's city is remembered in the browser (12.4). The nearest city is found in the browser only, with the visitor's permission (12.5). Shows "Weather data © OpenWeather". |
| Footer: menus, social links, newsletter form | `/menus` (`footer_main`, `footer_legal`), `/site` | P0 | The newsletter form posts to the website's server route (see Newsletter). |
| Ad slots: top banner, side, inside an article, between home sections | `/ads` | P0 | Each slot keeps its size even while empty (13.10). A view counts once half the ad is on screen (13.6). Views go through `POST /ads/impressions` from the server route. |
| Ad pop-up, mobile bar at the bottom | `/ads`, `/site` switches `ad_popup`, `ad_mobile_sticky` | P1 | Both are off by default. |
| Chatbot button and window | `/chat`, `POST /chat/messages` | P1 | Loads only when the visitor presses the button (18.15). Full screen on mobile, and works with the keyboard. |
| Page views | `POST /stats/hits` | P0 | Sent by the website's server; the browser only reports the address. `post_id` is resolved on the server. |

## Home and news

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| `/` | Home | `/home` (blocks in the admin's order) + the layout data | P0 | JSON-LD `NewsMediaOrganization` + `WebSite` with `SearchAction`. The block types: `hero`, `breaking`, `markets`, `latest`, `ad`, `category`, `most_read`, `videos`, `playlist`, `section`, `awareness`, `newsletter`. |
| `/news/{id}/{slug}` | Article: news, report, opinion, infographic | `/posts/{id}/{slug}` | P0 | This template is shared by every kind (P0). Covers: canonical, Open Graph, the JSON-LD named by `kind.schema_type`, breadcrumb, authors with links (or «فريق رَقَم», 6.5), the dates, the correction note, the sponsored label with `rel="sponsored"`, tags, related items with "more from this category", the in-article ad, a newsletter box at the end. Infographic: large view with zoom and a download button (9.11). |
| `/news` | Everything under `/news` | `/posts?kind=news,report,opinion,infographic&page=N` | P1 | The newest first, 12 per page. Needs `kind` to accept several kinds (backend change, below). |
| `/category/{slug}` | Category | `/categories/{slug}`, `/posts?category={slug}&page=N` | P0 | An introduction, sub-categories, the newest first, 12 per page (4.6). RSS feed of its own. |
| `/tag/{slug}` | Tag | `/tags/{slug}`, `/posts?tag={slug}` | P0 | Exists while at least one live item carries the tag. |
| `/author/{slug}` | Writer | `/authors/{slug}`, `/posts?author={slug}` | P0 | `ProfilePage` + `Person` (23.9). |
| `/guest/{slug}` | Guest | `/guests/{slug}`, `/posts?guest={slug}` | P1 | Every appearance of the guest (5.10, 6.11). |
| `/search` | Search | `/search` (`q`, `category`, `section`, `kind`, `from`, `to`, `sort`, `page`) | P0 | Dynamic, noindex. Results update as the visitor types, 250 ms after they stop (10.1), through a server route. With no results: suggestions, the latest items and the most read (10.10). |

## The seven special sections

Each section has a page at `/{section}` and items at `/{section}/{id}/{slug}`. Item pages use the article template with
the kind's own parts (`details` in `/posts/{id}`).

| Address | Section | Data | Priority | Kind-specific parts |
|---|---|---|---|---|
| `/podcast` | Podcast | `/sections/podcast`, `/posts?section=podcast&season={number}`, `/playlists` (seasons) | P0 | Episode number, season, guests, chapters that move the video to their time (5.1, 8.8). `VideoObject` with `Clip`. |
| `/success-stories` | Success stories | `/sections/success-stories`, `/posts?section=success-stories&sector={slug}` | P0 | Organisation, sector, city, year founded, highlighted figures (5.2). |
| `/made-in-nablus` | Made in Nablus | `/sections/made-in-nablus`, `/posts?section=made-in-nablus&sector={slug}` | P0 | Product and maker, sector, an optional contact link or phone, a large gallery (5.3, 9.6). |
| `/history` | Nablus economic history | `/sections/history`, `/posts?section=history&order=timeline&era={id}`, `/eras` | P0 | A timeline from the oldest to the newest: era, then year (5.4). |
| `/initiatives` | Initiatives | `/sections/initiatives`, `/posts?section=initiatives&initiative_status=ongoing\|ended` | P0 | Name, organiser, status filter, link (5.5). |
| `/round-table` | Round table | `/sections/round-table`, `/posts?section=round-table` | P0 | Date, participants with photos, chapters (5.6). |
| `/awareness` | Awareness | `/awareness` (the section, characters, newest cards), `/posts?section=awareness&character={slug}` | P0 | A card is a character speaking the information (7.11). The characters are Rive animations, with a still image under reduced motion or on weak devices (7.10). |
| `/awareness/characters/{slug}` | One character | `/characters/{slug}`, `/characters/{slug}/cards` | P1 | Everything the character has said (7.13). |

Every section page has an introduction, a featured item, the newest items, filters, and 12 items per page (5.9). Each
section also has its own RSS feed. List items carry the kind's `facts`, so a card shows the episode number and season,
the story's organisation and highlighted figure, the product's maker, the round table's date or the card's character
without opening the item.

**Filters (first version).** The filters are decided as follows:

- podcast: season;
- success stories: sector;
- Made in Nablus: sector;
- history: era (the list from `/eras`);
- initiatives: status;
- awareness: character;
- round table: no filter;
- no section filters by city.

A filtered page (any filter in the query) is `noindex`, and its canonical address is the section page itself.

## Video

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| `/video` | Video library | `/videos?section=&playlist=`, `/playlists` | P1 | Filter by section and playlist (8.10). |
| `/video/{id}` | One video | `/videos/{id}` | P1 | A light player: the picture and a play button, with YouTube (`youtube-nocookie`) loaded only on press (8.6, 8.7). Chapters. `VideoObject` (`structured_data`). |
| `/video/playlists/{slug}` | Playlist | `/playlists/{slug}` | P1 | Videos in order (8.9). |

## Markets and weather

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| `/currencies` | Currencies | `/currencies`, `/currencies/converter`, `/currencies/{code}/history?days=30` | P0 | A table, the converter (shows whether the buy or the sell rate is used, 11.7), a 30-day chart (Recharts), "last updated", and a note that the rates are indicative. Indexable with a strong title (11.12). |
| `/currencies/{code}` | One currency («سعر الدولار مقابل الشيكل اليوم») | `/currencies` (the currency's row), `/currencies/{code}/history?days=30` | P1 | Today's rate, buy and sell, the change, the converter preset to this currency, the chart. Indexable, in the sitemap. `code` is the ISO code in lower case (`/currencies/usd`). |
| `/weather` | Weather | `/weather/widget`, `/weather/cities` | P0 | All cities. |
| `/weather/{slug}` | One city («طقس نابلس اليوم») | `/weather/{slug}` | P0 | Now, low/high, humidity, wind, a 5-day forecast, day and night icons in the brand's style (12.6–12.8). Slugs are Arabic (`/weather/نابلس`). Indexable. |

## Services, advertising and the platform's own pages

The static pages live at the root (`/{slug}`); the panel creates the 13 below (decision 28). A page the admin adds later
gets the same template.

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| `/services` | Services and prices | `/pages/services`, `/services` | P0 | Cards in groups (media coverage / ads), with the price and a «تواصل للحجز» button (14.6). This page is how visitors book in V1. |
| `/services/{slug}` | One service | `/services/{slug}`, `POST /services/{slug}/contact` | P0 | Details, the service's own FAQ, examples of published work, contact buttons: WhatsApp with a ready message, call, email with a ready subject (14.7, 14.8). `Service`/`Offer` JSON-LD. The online booking button stays hidden while booking is off. |
| `/advertise` | «أعلن معنا» | `/pages/advertise`, `/advertise` | P1 | Slots, sizes, contact buttons (13.14). |
| `/contact` | «اتصل بنا» | `/pages/contact`, `/site` (contact, `map_url`), `POST /contact` | P0 | The form: name, email, optional phone, subject, message, Turnstile, honeypot `website`. Also phone, WhatsApp, email, address, map, social links. |
| `/faq` | FAQ | `/pages/faq`, `/faqs` | P1 | `FAQPage` JSON-LD. |
| `/team` | The team | `/pages/team`, `/team` | P1 | Writers in the admin's order. |
| `/about` | About | `/pages/about` | P0 | |
| `/editorial-policy`, `/corrections-policy`, `/sponsored-policy` | Credibility pages | `/pages/{slug}` | P0 | Needed for Google News (23.13). |
| `/privacy`, `/terms`, `/cookies`, `/copyright` | Legal pages | `/pages/{slug}` | P0 | |
| `/{slug}` | Any other published page | `/pages/{slug}` | P1 | The reserved words in `StaticPages::RESERVED` can never be a page's slug. |

## Newsletter (noindex)

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| `/newsletter` | Subscribe, then enter the code | `POST /newsletter/subscribe`, `/confirm`, `/resend` | P0 | Email and an optional name, Turnstile, honeypot. The page keeps the first challenge, offers "resend" after a minute, and blocks double submits. The answer is the same for everyone, so it never reveals who is subscribed. |
| `/newsletter/unsubscribe?s=&t=` | Unsubscribe | `POST /newsletter/unsubscribe` | P0 | The link in every newsletter email (`NewsletterLinks::unsubscribeUrl`). |

## System pages and files

| Address | Page | Data | Priority | Notes |
|---|---|---|---|---|
| any unknown address | 301 / 410 / 404 | `/redirects/lookup?path=` | P0 | The lookup is asked first. A 404 shows search and the latest items, and is reported through `/stats/hits` (`not_found`). A 410 says the item was removed. |
| error | 500 page | none | P0 | Static, in the brand. |
| `/sitemap` | Sitemap for visitors | `/sections`, `/categories`, `/pages`, `/menus` | P1 | Decision 28. Built from the lists, since `/sitemaps/pages` has addresses only. |
| `/robots.txt` | robots | `/robots` | P0 | One group per entry. Refreshed on the `site`/`robots` webhook. |
| `/sitemap.xml` and its parts | XML sitemaps | `/sitemaps`, `/sitemaps/pages`, `/sitemaps/posts?page=N`, `/sitemaps/news`, `/sitemaps/videos` | P0 | The website writes the XML. Part addresses are set when built. |
| RSS (site, category, section) | Feeds | `/feeds/posts`, `/feeds/categories/{slug}`, `/feeds/sections/{slug}` | P0 | Addresses are set when built; `rss`, `feed` and `feeds` are reserved for them. |
| `/llms.txt` | llms.txt | `/llms` | P1 | |
| `/{INDEXNOW_KEY}.txt` | IndexNow key | the website's own environment | P0 | Only the key as content. |
| Open Graph pictures | Share pictures | the `og` variant of the cover | P0 | Generated in the brand for pages without a picture (23.8). |

## Not built in V1

- Booking, online payment and customer accounts (`/bookings`, `/account`, payments): decisions 15–17. The API answers
  404 while their switches are off.
- Post preview: the admin's secret link is rendered by the back end (`/preview/{post}`), not by the website (see gap 6).

## API gaps

| # | Gap | Decision | Priority |
|---|---|---|---|
| 1 | No market indicators beyond currencies: gold, fuel prices, the stock exchange, inflation. | Waiting for a decision on whether to have them and on their sources. | later |
| 2 | List items carry `facts` only for history and initiatives. | Add `facts` for episode, story, product, round table and awareness, with no extra query per item. | P0 (section pages) |
| 3 | The API had only the initiative status filter. | Add `season`, `sector`, `era` and `character` to `/posts`, plus an `/eras` list. | P1 |
| 4 | `kind` took one value, so there was no list of everything under `/news`. | `kind` accepts several kinds, comma-separated; the `/news` page is P1. | P1 |
| 5 | No filter for short videos (`is_short` exists on YouTube videos; uploads are short by definition, 8.11). | A `short=1` parameter on `/videos` if the chosen home design has a shorts rail (decided in step 5). | P1 |
| 6 | The preview link opens the back end's own template, so a draft does not look like the published page. | Later: a signed, short-lived read of the draft for the website, plus a `/preview` page. | later |
| 7 | Currency pages had no address of their own. | `/currencies/{code}` (one page per visible currency), in the sitemap and with a `path` on each currency. | P1 |

Gaps 2, 3, 4 and 7 are closed in one backend change with tests; none needs a schema change.

## For the design foundations (step 6)

- **Header logo without the slogan.** At header size the slogan «تاريخ الاقتصاد ومستقبله» cannot be read. A version
  of the Arabic horizontal logo without it is needed, cut from the same source as the other logos. The bilingual logo
  has no slogan, but it carries the English name.
