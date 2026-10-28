---
name: رقم
description: تاريخ الاقتصاد ومستقبله. The design system of the Raqam website.
colors:
  vivid-blue: "#124FFF"
  caribbean-blue: "#21CEEA"
  brand-gray: "#8C8C8C"
  banner-blue: "#198FF4"
  banner-periwinkle: "#89A7FF"
---

# رقم: design system

## Overview

This is the first version. It records what is already fixed by the brand and by the product: the colours and how they
may be used, the shapes of the logo, and the rules for Arabic, right-to-left, mobile-first pages. These are decided in
the next steps and added here when they are:

- the typefaces (a type study at phone and desktop sizes);
- the neutral palette, spacing, radii and elevation;
- the motion principles;
- the overall visual direction (chosen from three home-page directions).

Until then, no page may introduce a colour, typeface, spacing value or radius that is not in this file.

**Character.** Trustworthy, close, clear. Never loud: no sensational styling and nothing that looks like clickbait.
Two readers set the bar:

- someone on a cheap phone and a slow connection who wants today's important news and today's dollar rate in seconds;
- a company owner or a banker deciding whether to put their name beside the platform.

The design must satisfy both at once: fast to read, and serious enough to trust.

**The idea to express.** «خلف كل رقم حكاية». A number always comes with its story, and the economy's history sits
beside its future. The logo carries this in its own shapes: the small dark shape is the past, the big cyan shape is the
future, and the kashida of the wordmark draws a line through time.

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

Fixed:

- **Typefaces designed for Arabic**, under the Open Font License, self-hosted. They load with `font-display: swap` and a
  metric-matched fallback, so text never jumps.
- **Candidates for the type study:** Alexandria, Readex Pro, IBM Plex Sans Arabic, Noto Naskh Arabic. Cairo only if it
  clearly wins. The study compares them at 375 px and 1440 px with real headlines, body text and numbers.
- **Line height of at least 1.6 for body text.** Longer Arabic texts and an older audience call for generous leading.
- **No letter-spacing on Arabic text**, ever. It breaks the joins between letters.
- **Comfortable sizes:** body text never below 16 px on a phone. Sizes are fluid (`clamp()`) between the phone and desktop
  values.
- **Digits are 0-9** everywhere, also in Arabic text. Rates and tables use tabular figures, so the columns line up.
- **The kashida is the logo's.** Running text and titles never use typed tatweel (ـ) to stretch words. It breaks search,
  copying and screen readers.

Decided by the type study: the families, the weights, the scale, and which face is used for headlines, body and numbers.

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
