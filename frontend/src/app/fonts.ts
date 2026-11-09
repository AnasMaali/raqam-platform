import { Alexandria } from "next/font/google";

/**
 * Alexandria for everything, served from this site. Only the Arabic file is preloaded; the Latin file (it holds the
 * digits 0-9) loads when a page shows a number. Next.js adds a metric-matched fallback, so text does not jump when the
 * font arrives.
 */
export const alexandria = Alexandria({
  subsets: ["arabic"],
  display: "swap",
  variable: "--font-alexandria",
});
