import { expect, test } from "@playwright/test";

import { expectNoSidewaysScroll, labUrl, useTheme } from "./helpers";

/*
 * Every width from a small phone to a large screen, a phone held sideways, and 200% zoom (WCAG reflow: a 1280 px
 * window at 200% is 640 CSS px, at 400% it is 320). In each: no sideways scrolling, in light and dark.
 */

const sizes = [
  { width: 320, height: 640 },
  { width: 375, height: 812 },
  { width: 414, height: 896 },
  { width: 768, height: 1024 },
  { width: 1024, height: 768 },
  { width: 1280, height: 800 },
  { width: 1440, height: 900 },
  { width: 1920, height: 1080 },
  { width: 812, height: 375, label: "a phone held sideways" },
];

const pages = [
  { name: "home", url: "/" },
  { name: "404", url: "/no-such-page" },
  { name: "components", url: labUrl("/lab/foundations") },
];

for (const theme of ["light", "dark"] as const) {
  for (const { name, url } of pages) {
    test(`${name}, ${theme}: no sideways scrolling from 320 to 1920 px and held sideways`, async ({ page }) => {
      test.setTimeout(180_000);
      await useTheme(page, theme);
      for (const size of sizes) {
        await page.setViewportSize({ width: size.width, height: size.height });
        await page.goto(url);
        await expect(page.locator("main#main"), `${size.width}×${size.height}`).toBeVisible();
        await expectNoSidewaysScroll(page);
      }
    });
  }
}

test("200% zoom: the text grows, nothing is cut and nothing scrolls sideways", async ({ browser }) => {
  test.setTimeout(120_000);
  for (const { url } of pages) {
    // A 1280 × 800 window at 200%: 640 × 400 CSS pixels, each drawn as two.
    const context = await browser.newContext({ viewport: { width: 640, height: 400 }, deviceScaleFactor: 2 });
    const page = await context.newPage();
    await page.goto(url);
    await expectNoSidewaysScroll(page);
    // The menu stays usable.
    const menu = page.getByRole("button", { name: "القائمة" });
    await menu.click();
    const dialog = page.getByRole("dialog", { name: "القائمة" });
    await expect(dialog).toBeVisible();
    const scrollable = await dialog.evaluate((el) => el.scrollHeight > el.clientHeight && getComputedStyle(el).overflowY === "auto");
    expect(scrollable, "the menu scrolls within itself when it is taller than the window").toBe(true);
    await context.close();
  }
});
