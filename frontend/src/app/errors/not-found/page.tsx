import type { Metadata } from "next";

import NotFound from "../../not-found";

/*
 * The page the check before the page (src/proxy.ts) shows, with a 404, for every address with nothing at it: the site's
 * not-found page (app/not-found.tsx), drawn as a page of its own. Next.js's built-in not-found route announces no font
 * in its answer's Link header (it looks the font up under a file name the font manifest does not hold), so the page
 * was drawn in the fallback font first and moved when the font came. A page that calls notFound() instead gets the
 * header, but its prerendered HTML is empty (the frame and the text come only with the scripts). Visited directly,
 * this address answers 404 too (the check sends it here).
 */

export const metadata: Metadata = { title: "الصفحة غير موجودة", robots: { index: false } };

export default function MissingPage() {
  return <NotFound />;
}
