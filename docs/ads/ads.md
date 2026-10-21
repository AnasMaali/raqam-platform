# Direct ads (decision 13)

Everything is managed from the panel (**Ads → Campaigns / Slots / Advertisers**). No outside ad network is used in V1
(AdSense is off, 13.15).

## Slots

Six fixed slots (`ad_placements`), edited only:

| Code | Where | Desktop | Mobile | Default |
|---|---|---|---|---|
| `header_leaderboard` | top of the page | 970×90 | 320×100 | on, loads at once |
| `sidebar_mpu` | sidebar | 300×250 | 300×250 | on, lazy |
| `in_article` | inside a post | 728×90 | 300×250 | on, lazy |
| `between_sections` | between home sections | 970×250 | 320×100 | on, lazy |
| `popup` | popup, once per visitor per N hours | 600×500 | 320×400 | **off** |
| `mobile_sticky` | bar at the bottom of phones | — | 320×50 | **off** |

A slot shows only when it is enabled **and**, for the popup and the mobile bar, its feature switch (`ad_popup`,
`ad_mobile_sticky`, Settings → Feature switches) is on too. Both are off by default.

An empty slot shows the slot's «أعلن معنا» banner (`house_ads`, a picture per device and an internal path such as
`/advertise` or an https address) or nothing, as chosen per slot (13.13). A slot set to the banner but without a picture
shows nothing.

## Campaigns

Advertiser (a company from `organizations` plus a contact), slots, a desktop and a mobile picture from the media library,
the advertiser's address, start and end (date and time, Palestine), priority 1–100 and status (draft / active / paused).

Checked when saving (`App\Ads\AdCampaigns`):

* the address is **https only**, of a real host name: no `http:`, `javascript:`, `data:`, `//host`, no user or password
  inside it (`https://bank.ps@evil.example`), no other port, no spaces or control characters, at most 2048 characters;
* at least one slot, and a picture for every device the chosen slots have a size for (the mobile bar needs no desktop
  picture);
* every picture is a public raster image of the library (JPG/PNG/WebP/AVIF), with the slot's shape (within 5%) and at
  least the slot's size. Slots of different shapes cannot share one picture: make one campaign per shape;
* the end is after the start, and a new or changed end lies in the future (an ended campaign can still be edited);
* a campaign with counted views or clicks is never deleted (its report stays); pause it instead.

"Ended" is not stored: the `live_ad_campaigns` view (active, started, not ended, slot enabled) decides what is live
(13.4). Every change is in the audit log (slots and pictures included, from → to).

## The API for the site

`GET /api/v1/ads` (contract: `docs/api/openapi.yaml`) returns every slot with its sizes, `lazy`, the popup's
`frequency_cap_hours`, `empty`, the live campaigns (`ads`) and the `house` banner. It is cached and never kept past the
next start or end of a campaign; a change in the panel sends the webhook `{"type": "ads", ...}`. A campaign starting or
ending by itself sends nothing: fetch `/ads` at least every minute or two, and drop any ad whose `starts_at`/`ends_at` no
longer hold when the page renders.

### Rendering a slot

* Reserve the slot's size for the device before the picture loads (13.10): no layout shift. Skip a slot with
  `active: false` or without a size for the device.
* Pick one campaign with weighted random choice (13.5):

```ts
function pickAd(ads: Ad[], now = Date.now()): Ad | null {
  const live = ads.filter(a => Date.parse(a.starts_at) <= now && now < Date.parse(a.ends_at));
  const total = live.reduce((sum, a) => sum + a.weight, 0);
  let r = Math.random() * total;
  for (const a of live) { if ((r -= a.weight) < 0) return a; }
  return null;   // nothing live: show slot.house if present, otherwise nothing
}
```

* Mark it with `meta.label` ("إعلان") and link the picture to `click_url` with `rel="sponsored noopener"` and
  `target="_blank"`. Never build the click address and never link to the advertiser directly.
* `lazy: true` → `loading="lazy"` (13.11); the header and the mobile bar load at once.
* The popup: show it at most once per `frequency_cap_hours` per browser (keep the time in `localStorage`; no cookie).

### Counting a view (13.6)

A view counts when at least half of the ad is on screen. Report each ad once per page view:

```ts
const viewId = crypto.randomUUID();   // one per page view
const seen = new Set<string>();
const io = new IntersectionObserver(entries => {
  const items = entries.filter(e => e.isIntersecting).map(e => (e.target as HTMLElement).dataset)
    .filter(d => !seen.has(d.ad + d.slot)).map(d => (seen.add(d.ad + d.slot), { campaign_id: Number(d.ad), placement: d.slot }));
  if (items.length) navigator.sendBeacon('/api/ads/impressions', new Blob([JSON.stringify({ view_id: viewId, items })], { type: 'application/json' }));
}, { threshold: 0.5 });
```

`/api/ads/impressions` is a route **of the website** (same domain): it forwards the body to
`POST /api/v1/ads/impressions` with `X-Raqam-Site-Token`, `X-Raqam-Visitor-Ip` and `X-Raqam-Visitor-Ua` (the visitor's
User-Agent). The browser never holds the token, and CORS stays off.

The API counts a view only when: the agent is a person's browser (robots, link previews, monitors, headless browsers and
HTTP libraries are not counted); the campaign is live in that slot at that moment; the same `view_id` was not counted
for it before; the same visitor did not count the same campaign and slot in the last 10 seconds; and the visitor is under
240 counted views an hour. Each visitor may send 60 reports a minute (then 429). The answer is always 204.

### Counting a click (13.7)

`click_url` is `/api/v1/ads/click/{campaign}/{slot}/{signature}`. It counts the click (people only, once per 30 seconds per
visitor, campaign and slot, at most 20 an hour per visitor), then answers **302** to the address stored with the
campaign. The link carries no destination, so it cannot be used to send anyone elsewhere; the signature (an HMAC under the
application key) stops anyone from making links for other campaigns or slots. A link that was never issued, or for a
draft, is a 404. A paused or ended campaign still sends the visitor on, without counting. The link itself has **no
request limit**: a person always reaches the advertiser (behind a proxy many people share one address); only the counting
is limited. Floods of requests are for the edge (Cloudflare rate rules).

### Privacy

No address is stored or used as a key: every per-visitor counter is keyed by an HMAC of the address, and view ids only
appear inside hashed keys. Counts are per campaign, slot and Palestine day (`ad_stats_daily`); nothing else is kept.

## Reports (13.8)

Ads → Campaigns → **Report**: views, clicks and click rate (clicks ÷ views, two decimals, rounded half up, computed in
integers) per day and per slot for any period up to two years, with Excel (OpenSpout, MIT) and PDF (mPDF, GPL-2.0)
copies. Both are right to left; Excel cells holding text are always text, so a name starting with `=` never becomes a
formula.

## Alerts (13.9)

The panel's home lists the running campaigns and warns about every active campaign ending within 3 days. The e-mail of
13.9 belongs to the alerts phase (it will use `ad_campaigns.ending_alert_sent_at`).

## AdSense (13.15)

Off in V1. `ADSENSE_CLIENT` (empty) is the only switch: `/ads` reports `meta.adsense`, and the site may use it as a
filler later without any other change.
