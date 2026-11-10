import localFont from "next/font/local";

/**
 * Alexandria for everything, served from this site as ONE preloaded file: the Arabic letters together with the space,
 * the digits 0-9 and the punctuation, weights 400 to 700 (the only ones the tokens use). Google's split files put the
 * space (U+0020) in the Latin file, so every Arabic line needed a second file that the browser found only while laying
 * the page out, and redrew the spaces when it came. The file is made by scripts/subset-font.sh; the licence is next to
 * it. Next.js adds a metric-matched fallback, so text does not jump when the font arrives.
 */
export const alexandria = localFont({
  src: "../fonts/alexandria-arabic-latin.woff2",
  weight: "400 700",
  style: "normal",
  display: "swap",
  variable: "--font-alexandria",
  adjustFontFallback: "Arial",
});
