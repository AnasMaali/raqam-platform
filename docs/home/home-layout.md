# Home page order (decision 21.11)

## Panel: «الموقع → ترتيب الرئيسية»
- A list of blocks: the main story, breaking news, latest news, a category, a special section, the latest videos, a
  playlist, awareness, the most read, currencies and weather, an ad slot, the newsletter.
- Drag a block into place, or move it with the arrows (phones); show or hide it; give it a title and a number of items
  (1–24, empty = the block's default); pick its category, section, playlist or ad slot (only the slots that are places on
  the page: top banner, between sections, side box).
- The same category, section, playlist or slot twice, or a one-off block (main story, breaking, latest, most read,
  markets, newsletter, awareness, videos) twice, is refused; at most 40 blocks.
- Before the first save the screen shows a suggested order (fixed blocks, each main category, every special section);
  `php artisan home:install` writes it once at deploy and never touches an order that exists.
- Saving is one transaction (positions are unique only at its end, so blocks can swap places), is written to the audit
  log (`settings`, field `home.layout`, before and after) and tells the site at once: webhook `type: home`,
  `paths: ["/"]`.

## API: `GET /api/v1/home`
The visible blocks in order, each with what it shows now (contract: `docs/api/openapi.yaml`, `HomeBlock`): posts (live
only) for the main story (featured first), breaking, latest, a category (with its sub-categories), a section, awareness
and most read; videos for the latest videos and a playlist (in its own order). A block whose category, section or
playlist is hidden, or that has nothing to show, is left out. Currencies/weather, ads and the newsletter name the
endpoints to read, which keep their own caches and counting.
