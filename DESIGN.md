---
name: رقم
description: تاريخ الاقتصاد ومستقبله. The design system of the Raqam website.
colors:
  vivid-blue: "#124FFF"
  caribbean-blue: "#21CEEA"
  brand-gray: "#8C8C8C"
  banner-blue: "#198FF4"
  banner-periwinkle: "#89A7FF"
  ground: "#FFFFFF"
  surface: "#F4F6FA"
  tint: "#EEF2FF"
  ink: "#10131A"
  muted: "#535A68"
  line: "#DCDFE6"
  line-strong: "#7D8491"
  brand: "#124FFF"
  rise: "#0B7A43"
  fall: "#C0322B"
  breaking: "#B42318"
  breaking-surface: "#FDEEEC"
  success: "#0B7A43"
  error: "#C0322B"
  ground-dark: "#0B0E14"
  surface-dark: "#131824"
  tint-dark: "#141B2E"
  ink-dark: "#EEF1F6"
  muted-dark: "#A7AEBC"
  line-dark: "#262C3A"
  line-strong-dark: "#6B7384"
  brand-dark: "#89A7FF"
  rise-dark: "#4CC38A"
  fall-dark: "#FF8F87"
  breaking-dark: "#FF8F87"
  breaking-surface-dark: "#2A1517"
rounded:
  xs: "2px"
  sm: "4px"
  md: "8px"
spacing:
  unit: "4px"
  page: "84rem"
  text: "42rem"
typography:
  body:
    fontFamily: "Alexandria, system-ui, sans-serif"
    fontSize: "clamp(1rem, 0.9rem + 0.4vw, 1.125rem)"
    fontWeight: 400
    lineHeight: 1.8
    letterSpacing: "0"
  label:
    fontFamily: "Alexandria, system-ui, sans-serif"
    fontWeight: 500
    letterSpacing: "0"
  headline:
    fontFamily: "Alexandria, system-ui, sans-serif"
    fontWeight: 700
    letterSpacing: "0"
  display:
    fontSize: "clamp(1.75rem, 1.35rem + 1.9vw, 3rem)"
    lineHeight: 1.3
  title:
    fontSize: "clamp(1.5rem, 1.25rem + 1.15vw, 2.25rem)"
    lineHeight: 1.35
  heading:
    fontSize: "clamp(1.375rem, 1.25rem + 0.6vw, 1.75rem)"
    lineHeight: 1.4
  subheading:
    fontSize: "clamp(1.125rem, 1.06rem + 0.3vw, 1.3125rem)"
    lineHeight: 1.55
  lead:
    fontSize: "clamp(1.0625rem, 1rem + 0.3vw, 1.25rem)"
    lineHeight: 1.8
  small:
    fontSize: "0.875rem"
    lineHeight: 1.7
  tiny:
    fontSize: "0.8125rem"
    lineHeight: 1.6
---

# رقم: design system

## Overview

It records what the brand and the product fix (the colours and how they may be used, the shapes of the logo, the
typeface, the rules for Arabic, right-to-left, mobile-first pages), the chosen visual direction, and the locked tokens:
colours for light and dark with their measured contrast, the type scale, spacing, radii and elevation. The tokens live in
`frontend/src/app/globals.css`; Tailwind's own palette, sizes and radii are switched off there.

No page may introduce a colour, typeface, size, spacing value or radius that is not in this file. A new value is added
here first, then used.

**Character.** Trustworthy, close, clear. Never loud: no sensational styling and nothing that looks like clickbait.
Two readers set the bar:

- someone on a cheap phone and a slow connection who wants today's important news and today's dollar rate in seconds;
- a company owner or a banker deciding whether to put their name beside the platform.

The design must satisfy both at once: fast to read, and serious enough to trust.

**The idea to express.** «خلف كل رقم حكاية». A number always comes with its story, and the economy's history sits
beside its future. The logo carries this in its own shapes: the small blue shape (`vivid-blue`) is the past, the big
cyan shape is the future, and the kashida of the wordmark draws a line through time.

## Visual direction

Chosen from three home-page directions, each built with real demo content and checked at 375, 768 and 1440 px in
light and dark: the directory, the kashida time line and the money changer's board. The base is **the directory**. It
takes one element from each of the other two, and each element follows the directory's rules.

### The directory (the base)

The home page reads like an economic directory of the kind chambers of commerce publish: paper and blue ink.

- **Chapters.** Every news category and every special section is a chapter (باب). A hairline rule opens it. Its name
  stands in blue at the start, and its items sit beside it.
- **Rank by space.** An item's weight shows in how many columns it takes, not in a louder colour or a decorative label
  above its headline.
- **Three items per chapter on phones**, with a link to the chapter's page. A chapter with nothing to show is left out.
- **The thumb index on large screens.** Tabs cut on the brand's slant stay on the page's edge and lead to each chapter,
  like the tabs on the edge of a printed directory.

### From the board: a small currency board in the first screen

- The board sits beside the lead story. It shows three currencies on phones and all of them from 1024 px.
- It is drawn by the directory's rules: the page's own background, hairline rules, blue headings and tabular figures.
  It is never a heavy dark block on a light page.
- Like every rates display, it shows the date of the rates and the note that they are indicative.

### From the kashida: the time line for history

- The history chapter on the home page, the economic history section and the archive hang their items on one time line:
  the kashida's stroke, with the «ق»'s hollow circle as each item's node and the year beside it.
- The line may draw itself as the reader scrolls, in CSS only. With reduced motion, or in a browser without scroll-driven
  animation, it is drawn complete and still.
- It is used nowhere else, so it keeps its meaning.

### Why

- **Trust first.** The directory speaks to the business reader at first sight: ordered, calm, serious. Every sector has
  a place of its own, and the page stays full with three to eight items a day.
- **The dollar habit, without becoming a rates site.** The board answers the daily visit in the first screen. Drawn in
  the directory's rules, it stays beside the news and not above it.
- **The tagline where time is the real axis.** The time line makes «تاريخ الاقتصاد ومستقبله» literal for history and
  the archive. As the axis of the whole home page it hid the sections and showed quiet news days, so it stays there.

### Not taken

- The board as a dark block, and the board's labelled rows for every section.
- Time as the only order of the home page.

### Risks to handle while building

- The page is long on phones: three items per chapter, and a clear way back to the top and to the chapters.
- The directory leans on pictures, and light pictures fade into a white page. Every picture keeps its frame and its
  size.

## Colors

The brand colours, for screens. The print colours of the brand file are not used on the web. The logo PDF printed the
cyan as #2AD7FF; on screen it is always `caribbean-blue`.

| Token | On white | On the dark background (#0B0E14) | Use |
|---|---|---|---|
| `vivid-blue` | 5.85 | 3.30 | The brand's main colour. Text and links on light backgrounds. On dark: large text and shapes only. |
| `caribbean-blue` | 1.90 | 10.19 | Shapes only on light backgrounds, never text or thin lines. On dark it may carry text. |
| `brand-gray` | 3.36 | 5.74 | Large text, borders and quiet shapes on light. Never body text on white. Secondary text on dark. |
| `banner-blue` | 3.35 | 5.77 | A support colour from the banners. Large text and shapes on light; text on dark. |
| `banner-periwinkle` | 2.32 | 8.31 | A support colour from the banners. Shapes only on light; text and links on dark. |

The numbers are contrast ratios. Body text needs 4.5 or more; large text and interface shapes need 3 or more.

### The site's colours

Every colour the site draws with is one of these tokens (`src/app/globals.css`). Tailwind's own palette is switched off,
so a class outside them draws nothing. The brand colours above are the source; the tokens are what pages use.

| Token | Light | Dark | Use |
|---|---|---|---|
| `ground` | #FFFFFF | #0B0E14 | The page. |
| `surface` | #F4F6FA | #131824 | A quiet surface: the footer, a picture's frame before it loads. |
| `tint` | #EEF2FF | #141B2E | A chapter tab, the hover of a control. |
| `ink` | #10131A | #EEF1F6 | Text. |
| `muted` | #535A68 | #A7AEBC | Secondary text: dates, notes, the line under a headline. |
| `line` | #DCDFE6 | #262C3A | Hairline rules between items (decoration, no contrast needed). |
| `line-strong` | #7D8491 | #6B7384 | The border of a control, which must be seen (3:1). |
| `brand` | #124FFF | #89A7FF | Links, chapter names, the focus ring. `vivid-blue` on light, `banner-periwinkle` on dark. |
| `on-brand` | #FFFFFF | #0B0E14 | Text on a `brand` surface (a chosen option, the skip link). |
| `rise` | #0B7A43 | #4CC38A | A rise, with its arrow and its + sign. |
| `fall` | #C0322B | #FF8F87 | A fall, with its arrow and its − sign. |
| `breaking` | #B42318 | #FF8F87 | The «عاجل» label. |
| `breaking-surface` | #FDEEEC | #2A1517 | The breaking bar. |
| `success` | #0B7A43 | #4CC38A | A form that went through. |
| `error` | #C0322B | #FF8F87 | A form error. |
| `past` | #124FFF | #124FFF | The brand's small blue shape, as a shape. |
| `future` | #21CEEA | #21CEEA | The brand's big cyan shape, as a shape. Never text on light. |
| `logo-past`, `logo-future` | blue, cyan | white, white | The header logo, drawn inline: the official white version on dark pages. |
| `scrim` | 55% of #0B0E14 | the same | Behind an open dialog. |

**Contrast, measured from the code** (`npm run check:tokens` fails the build when a pair drops below its ratio, or
when this table no longer matches the code):

<!-- contrast:start -->
| Pair | Light | Dark | Needs | Use |
|---|---|---|---|---|
| `ink` on `ground` | 18.58 | 17.06 | 4.5 | Text |
| `ink` on `surface` | 17.17 | 15.67 | 4.5 | Text on the footer and picture frames |
| `ink` on `tint` | 16.62 | 15.13 | 4.5 | Text on a tab |
| `muted` on `ground` | 6.93 | 8.67 | 4.5 | Secondary text |
| `muted` on `surface` | 6.41 | 7.96 | 4.5 | Secondary text in the footer |
| `muted` on `breaking-surface` | 6.14 | 7.74 | 4.5 | The time in the breaking bar |
| `brand` on `ground` | 5.85 | 8.31 | 4.5 | Links, chapter names, focus ring |
| `brand` on `surface` | 5.41 | 7.63 | 4.5 | Links in the footer |
| `brand` on `tint` | 5.23 | 7.37 | 4.5 | A chapter tab's name |
| `on-brand` on `brand` | 5.85 | 8.31 | 4.5 | Text on a brand-coloured control |
| `ink` on `breaking-surface` | 16.47 | 15.23 | 4.5 | The breaking headline |
| `ground` on `breaking` | 6.57 | 8.77 | 4.5 | The «عاجل» label |
| `breaking` on `ground` | 6.57 | 8.77 | 4.5 | «عاجل» in a card's line |
| `rise` on `ground` | 5.41 | 8.72 | 4.5 | A rise |
| `fall` on `ground` | 5.63 | 8.77 | 4.5 | A fall |
| `success` on `ground` | 5.41 | 8.72 | 4.5 | A success message |
| `error` on `ground` | 5.63 | 8.77 | 4.5 | An error message |
| `line-strong` on `ground` | 3.76 | 4.06 | 3 | A control's border |
| `line-strong` on `surface` | 3.48 | 3.73 | 3 | A control's border in the footer |
<!-- contrast:end -->

### Named rules

- **The cyan is the future.** `caribbean-blue` (`future`) marks what points forward: a highlight, what is new, the
  brand's big shape. Use it sparingly, so it keeps that meaning. A rise in a rate is `rise`, a green that can carry
  text in both themes.
- **Blue speaks, cyan shines.** On light pages `vivid-blue` carries text and links, and cyan stays a shape. On dark pages
  links and chapter names move to `banner-periwinkle` (8.31), and `vivid-blue` stays for shapes.
- **Rises and falls are never colour alone.** Currency changes show an arrow and a sign (+ or −) as well as any colour.
- **Breaking is quiet.** A tinted bar and a small slanted label. No flashing, no motion, no red block across the page.
- **The theme.** The device's setting by default. The header's switch picks light or dark; the footer and the phone menu
  also offer «حسب الجهاز» to go back. The choice is kept in the browser only, and applied before the page paints.

## Typography

**One family for everything: Alexandria** (headlines, body text, interface and numbers). It was chosen in a type study
of five Arabic typefaces at 375 px and 1440 px, with real headlines, body text, a currency table and numbers inside
sentences (the lab page `/lab/fonts`, development only). Why it was chosen:

- Its geometric letters speak the same language as the logo's «ق».
- At 16 px on a phone it reads larger and clearer than the other candidates. That matters for an older business
  audience.
- One variable file covers every weight (100–900).
- Its tabular figures are real (`tnum`), measured in the font files.

### Files

- Under the Open Font License, served from this site, with `font-display: swap` and a metric-matched fallback so text
  never jumps.
- About 60 KB for every weight: 30.6 KB for the Arabic file and 29.4 KB for the Latin file, which holds the digits 0-9.
- Only the Arabic file is preloaded. The Latin file loads when a page shows a number.
- Possible later: a Latin file cut down to digits and symbols measured 9.7 KB instead of 29.4 KB.

### Weights

| Token | Weight | Use |
|---|---|---|
| `body` | 400 | Running text. On dark pages it may go down to 350 if the text looks heavy (the family is variable). |
| `label` | 500 | Menus, buttons, labels, the currency strip. |
| `headline` | 700 | Headlines and titles. |

### Sizes and spacing

- **Body text:** 16 px on a phone, 17–18 px on larger screens. It is fluid
  (`clamp(1rem, 0.9rem + 0.4vw, 1.125rem)`): 16 px up to about 400 px wide, about 17.5 px on a tablet, 18 px from about
  900 px.
- **Line height 1.8 for body text**, never below 1.6 anywhere. Long Arabic texts and an older audience call for generous
  leading.
- **The type scale.** Seven sizes and a figure size, fluid between a 375 px phone and a 1440 px screen. Tailwind's own
  sizes are switched off; a page uses these names only.

  | Token | Phone → large screen | Line height | Use |
  |---|---|---|---|
  | `text-display` | 28 → 48 px | 1.3 | The main story's headline. |
  | `text-title` | 24 → 36 px | 1.35 | A page's title (`h1`). |
  | `text-heading` | 22 → 28 px | 1.4 | A chapter's name, a section heading. |
  | `text-subheading` | 18 → 21 px | 1.55 | A card's headline, a block's heading. |
  | `text-lead` | 17 → 20 px | 1.8 | A summary under a headline. |
  | `text-body` | 16 → 18 px | 1.8 | Running text, list headlines. |
  | `text-small` | 14 px | 1.7 | The line under a headline, menus, tables, the currency strip. |
  | `text-tiny` | 13 px | 1.6 | The word «إعلان», the weather credit, a rates note. Never a sentence to read. |
  | `text-figure` | 32 → 48 px | 1.2 | A story's number, the temperature. |

  Headlines are balanced over their lines (`text-wrap: balance`).
- **No letter-spacing on Arabic text**, ever. It breaks the joins between letters.

### Numbers and dates

- **Digits are 0-9** everywhere, also in Arabic text.
- **Tabular figures only in tables and the currency strip** (`font-variant-numeric: tabular-nums`), so the columns line
  up. Everywhere else the digits stay proportional; they sit better inside a sentence.
- **Signs:** a rise is `+`, a fall is the minus sign `−` (U+2212), not a hyphen. A signed number or percentage is kept
  left to right inside the Arabic line.
- **Month names:** the Levantine names (تشرين الأول) by default. «pending client» to confirm.
- Dates and numbers are formatted with the `Intl` APIs and the `-u-nu-latn` numbering, never by hand.

### The kashida

The kashida is the logo's. Running text and titles never use typed tatweel (ـ) to stretch words. It breaks search,
copying and screen readers.

## Layout

- **Spacing.** One step is 4 px (Tailwind's spacing unit). The steps in use: 4, 8, 12, 16, 24, 32, 40, 48, 64 px. Tight
  inside a group, generous between groups, more space above a heading than below it.
- **The page.** Content stops at 84 rem (1344 px) and sits in the middle beyond it. Side gutters: 16 px on phones, 24 px
  from 640 px, 32 px from 1024 px. Running text stops at 42 rem (about 70 characters).
- **Breakpoints.** 640, 768, 1024, 1280, 1536 px (`sm` to `2xl`). Every component is checked at 320, 375, 414, 768, 1024,
  1280, 1440 and 1920 px, on a phone held sideways, and at 200% zoom.
- **Mobile first.** Base styles are for the phone; wider layouts are added with `min-width` queries only. Every page is
  checked at 375, then 768, then 1440 px. Nothing scrolls sideways down to 320 px.
- **Right to left first.** `dir="rtl"`, logical CSS properties only (`margin-inline-start`, `inset-inline-end`, …).
  Directional icons such as arrows and "next" are mirrored; the logo and its slanted shapes never are.
- **Screen height** uses `dvh`/`svh`, never `100vh`.
- **Touch targets of at least 44 × 44 px.** Nothing works by hover alone.
- **The first screen on a phone** shows the most important story and the currency board (the dollar rate is a daily
  habit). On the home page the board takes the strip's place; every other page keeps the thin strip under the header.
- **Full with little content.** The site is built for about 3–8 items a day. A page or block never shows an empty
  section or a list waiting to fill; a block with nothing to show is left out.
- **Light pages.** Media below the first screen loads lazily, and every reserved space (ads, images, embeds) keeps its
  size, so nothing jumps.

## Shapes

The brand's own form language, taken from the logo:

- **The slant.** Rounded parallelograms at about 55°, as in the logo's two shapes. It is the brand's main graphic gesture
  (dividers, highlights, image crops, section markers) and keeps the logo's direction in right-to-left pages too.
- **The hollow circle** of the «ق», with its two dots. It can mark, frame or count.
- **Small to big, past to future.** Pairs of shapes in growing sizes tell the passage of time.
- **The kashida as a timeline.** The long stroke of the wordmark becomes the line of the history section's timeline.

**Corner radii.** The logo's shapes have softly rounded corners, not sharp ones and not pills. Three radii only:

| Token | Size | Use |
|---|---|---|
| `rounded-xs` | 2 px | Small labels («محتوى ممول»), the chosen option inside a control. |
| `rounded-sm` | 4 px | Buttons, controls, the skip link. |
| `rounded-md` | 8 px | Larger surfaces, when one is needed. |

Pictures and the directory's rules have square corners: a printed directory's.

**The slant in code.** `slant-end` cuts the end of a shape (its left) like the logo's parallelograms. The cut is 0.7 × the
shape's height for about 55°: 1.75 rem for a 44 px tab, set by `--slant` for other heights.

**Elevation.** One shadow, `shadow-raised`, for what floats over the page (a menu's list, «للأعلى»). Everything else is
flat, separated by hairline rules.

## Components

Built in `frontend/src/components/` and shown with all their states on the development page `/lab/foundations`.

- **Header.** The logo without the slogan (`logo-ar-header.svg`, drawn inline so it follows the theme), search, the
  theme switch and, below 1024 px, the menu button. From 1024 px the menu is a second row, and an item with children
  (the platform's sections) opens a list under it. Without the menus (the API is down) it still shows the logo, search
  and the theme switch.
- **Phone menu.** The browser's own modal dialog from the start side: the focus stays inside, Escape closes it, the focus
  returns to the button, the page behind does not scroll. It holds the menu with its groups written out (the chapters
  are there too) and the full theme choice.
- **Skip link.** The first stop for the keyboard, «انتقل إلى المحتوى», visible when focused.
- **Theme.** A switch in the header (light or dark), and «حسب الجهاز / فاتح / داكن» in the footer and the phone menu.
- **Breaking bar.** Only when there is breaking news: a tinted bar, a slanted «عاجل» label, the newest headline and its
  time.
- **Currency strip.** Thin, under the header of every page but the home page. Swiped by hand, never moving on its own.
  It ends with the rates' date and «أسعار استرشادية».
- **Currency board.** On the home page, beside the main story: three currencies on phones, all of them from 1024 px.
  Buy, sell and change, with the date and the platform's note that the rates are indicative.
- **Weather.** The default city with a drawn icon, the temperature and the condition; a stale reading says when it was
  taken; a few other cities; "Weather data © OpenWeather" always visible. Left out when no city has a reading.
- **Ad slot.** The word «إعلان» above it, its box reserved at the slot's phone size below 768 px and its desktop size
  above. A slot with no campaign shows the platform's «أعلن معنا» banner, or nothing at all (nothing reserved) when the
  slot says so. Links go through the platform's counting link with its `rel`.
- **Cards.** The main story (a large headline and summary beside a wide picture), a card with a picture (a success
  story shows its figure first), a compact card (headline and line), a ranked card (most read). The headline is the
  card's one link, stretched over the card; the focus ring goes round the whole card. Without a picture a card stays
  complete, with no empty box. Sponsored items carry «محتوى ممول» with the sponsor's name.
- **Chapters.** On phones and tablets a short row of slanted tabs, «الأبواب», after the first screen; from 1280 px the
  same tabs stand on the page's edge and stay while reading.
- **«للأعلى».** Appears a screen and a half down, goes back to the header.
- **Footer.** The full logo with its slogan, «خلف كل رقم حكاية، وخلف كل حكاية اقتصاد يتشكّل», the about and policy menus,
  the social accounts the admin made visible, the theme choice.
- **Pictures.** Every picture keeps its frame and its size: its box is reserved by its ratio (16:9 for the main story,
  3:2 for cards) and a hairline edge keeps a light picture from fading into a white page.

Still fixed by the product, for the components to come:

- **Sponsored content:** labelled «محتوى ممول», with `rel="sponsored"` on its links. It must always be visibly different
  from editorial content.
- **Awareness card:** a character speaking the information in a speech bubble. The character is animated, with a still
  image when motion is reduced or the device is weak.
- **Video:** a light player (the picture and a play button) that loads YouTube (`youtube-nocookie`) only when pressed.
- **Focus:** every interactive element shows a clearly visible focus ring in both themes.

## Motion

### Library

**Motion** (MIT license) is the only motion library. GSAP was weighed and not chosen: it handles exits in React by
hand, its license is not an open-source one, and its strength (long scroll-driven stories) works against a calm site
on weak phones. Rive stays only for the eight awareness characters.

Measured on 2026-10-28 by bundling each option the way the site would import it (React excluded, minified, gzip):

| Option | gzip | Gives |
|---|---|---|
| `motion/react-mini` (on the browser's animation engine) | 3.4 KB | Small animations started from code |
| `LazyMotion` + `m` + `domAnimation` | 32.2 KB | Enter and exit animations, gestures |
| … + `domMax` | 46.1 KB | Dragging (the gallery) and layout changes |
| GSAP core + `useGSAP` (not chosen) | 27.4 KB | |
| GSAP + ScrollTrigger (not chosen) | 44.6 KB | |

Motion advertises a 4.6 KB start with lazy features; bundled for real, the smallest working setup was 28 KB.

### Budget

- **The layout carries no motion code (0 KB).** There is no site-wide motion provider.
- **CSS first:** press, hover, focus and colour changes are CSS transitions. The history section's kashida timeline uses
  CSS scroll-driven animation (`animation-timeline: view()`), and a browser without it shows the line complete and
  still.
- **The phone menu is CSS too:** the native dialog slides in with `@starting-style` and slides out with
  `transition-behavior: allow-discrete`. A browser without them opens and closes it at once.
- **Motion only where CSS cannot do it:** exits CSS cannot express (the chat window, the image viewer), dragging (swiping
  the gallery), layout changes. The component that needs it loads Motion with a dynamic import, at the moment it is
  used. The gallery viewer loads `domMax` only when it opens.
- **A check guards it:** the build fails if Motion is found in the scripts the home page or an article page loads first.
- **Moving between pages:** no library. Either no transition, or the browser's own View Transitions. A browser without
  them simply navigates.

### Reduced motion

- Each component that loads Motion reads `prefers-reduced-motion` itself (`useReducedMotion`): movement and scaling are
  dropped and only opacity remains, or nothing.
- CSS motion sits inside `@media (prefers-reduced-motion: no-preference)`.
- The awareness characters show their still picture.
- No information depends on motion to be understood.

### Right to left

- Transforms are physical: `x: 20` always moves right, whatever `dir` says. Directions are written once as constants
  (from the start of the line to its end, that is from right to left), never as raw x values inside components.
- In the gallery, "next" lies to the left, so swiping right moves forward.
- Arabic text is never split into letters for an animation: that breaks the joins. Split by words or lines only.

### Principles

1. **Calm and precise.** Entrances 150–250 ms with ease-out, exits faster (120–180 ms). No bounce, no overshoot, no
   wobble: trustworthy, never loud.
2. **Numbers do not perform.** Rates and percentages appear at once. No counting up, no rolling digits. A change shows
   as a short, light tint (under 600 ms) with its arrow and sign. A moving number cannot be read and does not inspire
   trust.
3. **From the past to the future.** Motion that expresses time travels in the reading direction, from right to left,
   along the kashida line.
4. **The slant is a signature, not decoration.** A reveal with a 55° slanted edge, at most once per page: the main
   story's picture or a section opener.
5. **Small to big.** The loading mark is the logo's two shapes: the small blue one, then the big cyan one. It is the
   only recurring brand moment.
6. **Nothing waits for motion.**
   - Nothing animates before the largest element of the screen has painted (LCP), and that element never animates.
   - Only transform and opacity are animated.
   - No parallax on phones, and no taking over the scroll.
7. **No endless motion.** The currency strip stands still and is swiped by hand; it is not a marquee. Anything that
   moves on its own for more than 5 seconds has a pause control.

## Do's and Don'ts

### Do:

- Use only the tokens in this file. A new value is added here first, then used.
- Give every number its context: what it is, when it was measured, and what changed.
- Keep paid content visibly separate from editorial content.
- Respect `prefers-reduced-motion`: motion is never needed to understand or use anything.
- Check both themes. The site follows the device's setting and has a switch.

### Don't:

- No generic template look: no purple gradients, no glass cards everywhere, no centred hero with three cards.
- No sensational styling: no flashing, no screaming red badges, no all-caps alarm.
- No cyan text or thin cyan lines on light backgrounds.
- No maps or flags as decoration, and no faces in generic elements (until the client decides otherwise).
- No letter-spacing on Arabic, and no typed tatweel to stretch words.
- Never mirror, recolour or distort the logo. Use the files in `frontend/public/brand/`.
