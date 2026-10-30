---
name: رقم
description: تاريخ الاقتصاد ومستقبله. The design system of the Raqam website.
colors:
  vivid-blue: "#124FFF"
  caribbean-blue: "#21CEEA"
  brand-gray: "#8C8C8C"
  banner-blue: "#198FF4"
  banner-periwinkle: "#89A7FF"
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
---

# رقم: design system

## Overview

This is the first version. It records what is already fixed by the brand and by the product: the colours and how they
may be used, the shapes of the logo, the typeface, and the rules for Arabic, right-to-left, mobile-first pages. These
are decided in the next steps and added here when they are:

- the type scale (headline sizes), the neutral palette, spacing, radii and elevation.

The visual direction is chosen (see Visual direction below).

Until then, no page may introduce a colour, typeface, spacing value or radius that is not in this file.

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

### Named rules

- **The cyan is the future.** `caribbean-blue` marks what points forward: a highlight, a rise, what is new. Use it
  sparingly, so it keeps that meaning.
- **Blue speaks, cyan shines.** On light pages `vivid-blue` carries text and links, and cyan stays a shape. On dark pages
  the cyan becomes the brightest colour and may carry text. `vivid-blue` there is for large text and shapes only.
- **Rises and falls are never colour alone.** Currency changes show an arrow and a sign (+ or −) as well as any colour.

### Still to decide

- The neutrals: background, surfaces, text and lines, in light and dark. The code holds provisional values only.
- The semantic colours (success, warning, error, rise, fall).
- The exact pair of link colours for light and dark.

All of them must pass the ratios above in both themes.

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
- Headline sizes are part of the type scale, decided with the visual direction.
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

- **Mobile first.** Base styles are for the phone; wider layouts are added with `min-width` queries only. Every page is
  checked at 375, then 768, then 1440 px. Nothing scrolls sideways down to 320 px.
- **Right to left first.** `dir="rtl"`, logical CSS properties only (`margin-inline-start`, `inset-inline-end`, …).
  Directional icons such as arrows and "next" are mirrored; the logo and its slanted shapes never are.
- **Screen height** uses `dvh`/`svh`, never `100vh`.
- **Touch targets of at least 44 × 44 px.** Nothing works by hover alone.
- **The first screen on a phone** shows the most important story, with the currency strip visible (the dollar rate is a
  daily habit). The featured story comes after them.
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

Corner radii are decided with the other tokens. The logo's shapes have softly rounded corners, not sharp ones and not
pills.

## Components

Not yet designed. These rules are already fixed by the product:

- **Currency strip:** shows the date of the rates and the note that they are indicative.
- **Ads:** every slot keeps its size even while empty, and carries the word «إعلان».
- **Sponsored content:** labelled «محتوى ممول», with `rel="sponsored"` on its links. It must always be visibly different
  from editorial content.
- **Weather:** every place that shows weather says "Weather data © OpenWeather".
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
- **Motion only where CSS cannot do it:** exits (the mobile menu, the chat window, the image viewer), dragging (swiping
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
