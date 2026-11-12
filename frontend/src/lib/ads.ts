/*
 * The two ads that float over the page (13.2), as the server hands them to the browser: the popup (from 1024 px) and
 * the bar at the bottom of a phone's screen (below 768 px). Only what the browser needs to draw one, nothing more.
 */

export type FloatingAd = {
  placement: "popup" | "mobile_sticky";
  /** The campaign, counted when it is seen; null for the platform's own «أعلن معنا» banner, which is not counted. */
  campaign: number | null;
  href: string;
  rel?: string;
  image: { url: string; width: number; height: number };
  alt: string;
  label: string;
  /** The slot's reserved size on the device it shows on. */
  size: { width: number; height: number };
  /** Popup only: shown to a visitor at most once in this many hours. */
  capHours: number;
};

/** The extra height of the phone's bar around its picture (its padding), in px. */
export const barPadding = 4;

/**
 * Tells the page's statistics (components/site/ViewReporter) that an ad appeared after the page was drawn, so it is
 * counted once it is half on screen, like every other ad.
 */
export function announceAd(element: Element | null) {
  if (element) document.dispatchEvent(new CustomEvent("raqam:ad", { detail: element }));
}
