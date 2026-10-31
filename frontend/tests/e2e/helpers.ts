import { expect, type Page } from "@playwright/test";

/** The development lab: its own server (port 3200), or the one LAB_URL names. */
export const labUrl = (path: string) => `${process.env.LAB_URL ?? "http://127.0.0.1:3200"}${path}`;

/** Nothing on the page scrolls sideways. */
export async function expectNoSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, "the page scrolls sideways").toBeLessThanOrEqual(0);
}

/**
 * Every visible control is at least 44 × 44 px. A card's headline is exempt: its link is stretched over the whole card,
 * so the card is the target. Elements inside a strip that scrolls sideways are measured as they are, not as clipped.
 */
export async function smallTargets(page: Page, scope = "body") {
  return page.evaluate((scope) => {
    const small: string[] = [];
    const root = document.querySelector(scope);
    if (!root) return ["no scope"];
    for (const el of root.querySelectorAll<HTMLElement>("a[href], button, label:has(> input)")) {
      const style = getComputedStyle(el);
      if (style.visibility === "hidden" || el.closest("[hidden], dialog:not([open])") || el.getClientRects().length === 0) continue;
      if (el.matches(".sr-only, .sr-only *") && !el.matches(":focus")) continue;
      if (el.className.includes("after:inset-0")) continue;
      const box = el.getBoundingClientRect();
      if (box.width < 44 || box.height < 44) small.push(`${el.tagName.toLowerCase()} "${(el.textContent || el.getAttribute("aria-label") || "").trim().slice(0, 30)}" ${Math.round(box.width)}×${Math.round(box.height)}`);
    }
    return small;
  }, scope);
}

/** Use the reader's choice of theme the way the site stores it. */
export async function useTheme(page: Page, theme: "light" | "dark") {
  await page.addInitScript((value) => window.localStorage.setItem("raqam-theme", value), theme);
}
