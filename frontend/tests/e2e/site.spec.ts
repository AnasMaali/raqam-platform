import { expect, type Locator, test } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

const wide = (width: number) => width >= 1024;
/** The menu sheet's width beside a wider screen: 24rem. */
const SHEET_WIDTH = 384;

/** Waits until the sheet has slid in: no transition still running and its translate back to zero. */
async function settled(dialog: Locator) {
  await expect(dialog).toBeVisible();
  await expect
    .poll(() =>
      dialog.evaluate(async (el) => {
        await Promise.all(el.getAnimations().map((a) => a.finished));
        const translate = getComputedStyle(el).translate;
        return translate === "none" || translate === "0px" || translate === "0px 0px";
      }),
    )
    .toBe(true);
}

test.describe("the page frame", () => {
  test("the home page has the frame, in Arabic, right to left, without the currency strip", async ({ page }) => {
    const response = await page.goto("/");
    expect(response?.status()).toBe(200);
    await expect(page.locator("html")).toHaveAttribute("lang", "ar");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(page.getByRole("link", { name: "رقم، الصفحة الرئيسية" })).toBeVisible();
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.locator("main#main")).toHaveCount(1);
    await expect(page.locator("footer")).toBeVisible();
    // The home page has the board beside its main story instead of the strip.
    await expect(page.locator("#rates-strip-title")).toHaveCount(0);
    await expectNoSidewaysScroll(page);
  });

  test("one font file, announced before the page is read, holds the Arabic letters, the space and the digits", async ({ page }) => {
    // A second file (Google's Latin one holds the space) would be found only while the page is laid out, and would
    // redraw every space when it came: one request more on the way to the first paint of every page.
    for (const path of ["/", "/about", "/currencies/usd"]) {
      const fonts: string[] = [];
      page.on("request", (request) => {
        if (request.resourceType() === "font") fonts.push(new URL(request.url()).pathname);
      });
      const response = await page.goto(path);
      await page.evaluate(() => document.fonts.ready);
      expect(fonts, path).toHaveLength(1);
      expect(response?.headers().link ?? "", path).toContain(`<${fonts[0]}>; rel=preload; as="font"`);
      page.removeAllListeners("request");
    }
    // The space, the digits and an Arabic letter are all in it: none of them falls back to the system font.
    const covered = await page.evaluate(async () => {
      const family = getComputedStyle(document.body).fontFamily.split(",")[0].trim();
      await Promise.all([" ", "0123456789", "رقم"].map((text) => document.fonts.load(`16px ${family}`, text)));
      return [...document.fonts].filter((face) => face.status === "loaded").length;
    });
    expect(covered).toBe(1);
  });

  test("an unknown address answers 404 inside the frame", async ({ page }) => {
    const response = await page.goto("/no-such-page");
    expect(response?.status()).toBe(404);
    await expect(page.getByRole("heading", { level: 1, name: "الصفحة غير موجودة" })).toBeVisible();
    await expect(page.getByRole("link", { name: "رقم، الصفحة الرئيسية" })).toBeVisible();
    await expect(page.locator("footer")).toBeVisible();
    await expectNoSidewaysScroll(page);
  });

  test("the breaking bar shows the breaking headline, quietly", async ({ page }) => {
    await page.goto("/");
    const bar = page.locator("section", { has: page.locator("#breaking-title") });
    await expect(bar.getByRole("heading", { name: "عاجل" })).toBeVisible();
    await expect(bar.getByRole("link")).toBeVisible();
    // No animation of any kind on it.
    const animations = await bar.evaluate((el) => el.getAnimations({ subtree: true }).length);
    expect(animations).toBe(0);
  });

  test("every control in the header and the footer is at least 44 × 44 px", async ({ page }) => {
    await page.goto("/");
    expect(await smallTargets(page, "header")).toEqual([]);
    expect(await smallTargets(page, "footer")).toEqual([]);
  });
});

test.describe("keyboard", () => {
  test("the first Tab reaches the skip link, which moves the focus to the content", async ({ page }) => {
    await page.goto("/");
    await page.keyboard.press("Tab");
    const skip = page.getByRole("link", { name: "انتقل إلى المحتوى" });
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await page.keyboard.press("Enter");
    await expect(page.locator("main#main")).toBeFocused();
  });

  test("the focus ring is visible on the header's controls", async ({ page }) => {
    await page.goto("/");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Tab");
    const outline = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement;
      const style = getComputedStyle(el);
      return { width: parseFloat(style.outlineWidth), style: style.outlineStyle };
    });
    expect(outline.style).not.toBe("none");
    expect(outline.width).toBeGreaterThanOrEqual(2);
  });
});

test.describe("the theme", () => {
  test("follows the device until the reader chooses, then keeps the choice", async ({ page }) => {
    await page.emulateMedia({ colorScheme: "dark" });
    await page.goto("/");
    const ground = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(await ground()).toBe("rgb(11, 14, 20)");

    const toggle = page.getByRole("button", { name: "الوضع الداكن" });
    await expect(toggle).toHaveAttribute("aria-pressed", "true");
    await toggle.click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
    await expect(toggle).toHaveAttribute("aria-pressed", "false");
    expect(await ground()).toBe("rgb(255, 255, 255)");

    await page.reload();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
    expect(await ground()).toBe("rgb(255, 255, 255)");

    // «حسب الجهاز» in the footer gives the choice back to the device.
    await page.locator("footer").getByText("حسب الجهاز").click();
    await expect(page.locator("html")).not.toHaveAttribute("data-theme", /.+/);
    expect(await ground()).toBe("rgb(11, 14, 20)");
  });

  test("a stored choice applies before the first paint, without a flash", async ({ page }) => {
    await useTheme(page, "dark");
    await page.goto("/", { waitUntil: "commit" });
    await page.waitForSelector("body");
    expect(await page.evaluate(() => document.documentElement.dataset.theme)).toBe("dark");
  });
});

test.describe("the menu", () => {
  test("on phones and tablets: a dialog that keeps the focus, closes with Escape and gives the focus back", async ({ page }, info) => {
    test.skip(wide(info.project.use.viewport?.width ?? 0), "from 1024 px the menu is in the header");
    await page.goto("/");
    const button = page.getByRole("button", { name: "القائمة" });
    await button.click();
    const dialog = page.getByRole("dialog", { name: "القائمة" });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole("link", { name: "أسواق ومال" })).toBeVisible();
    await expect(dialog.getByRole("heading", { name: "أقسام رَقَم" })).toBeVisible();
    // The page behind does not scroll.
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).overflow)).toBe("hidden");
    // Tab stays inside the dialog.
    for (let i = 0; i < 5; i++) await page.keyboard.press("Tab");
    expect(await page.evaluate(() => !!document.activeElement?.closest("dialog"))).toBe(true);
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(button).toBeFocused();
    expect(await smallTargets(page, "header")).toEqual([]);
  });

  test("below 1024 px, where the screen is wider than the sheet (a tablet): a press outside it closes it", async ({ page }, info) => {
    const width = info.project.use.viewport?.width ?? 0;
    test.skip(wide(width), "from 1024 px the menu is in the header");
    test.skip(width <= SHEET_WIDTH, "up to 24rem (384 px) the sheet takes the whole width: there is nothing outside it to press");
    await page.goto("/");
    await page.getByRole("button", { name: "القائمة" }).click();
    const dialog = page.getByRole("dialog", { name: "القائمة" });
    await settled(dialog);
    const box = (await dialog.boundingBox())!;
    // The sheet is on the right, at its full width; the backdrop is the rest of the screen, on the left.
    expect(Math.round(box.x + box.width)).toBe(width);
    expect(Math.round(box.width)).toBe(SHEET_WIDTH);
    await page.mouse.click(box.x / 2, page.viewportSize()!.height / 2);
    await expect(dialog).toBeHidden();
  });

  test("on a phone of 375 px: the sheet takes the whole width, and its close button closes it", async ({ page }, info) => {
    test.skip((info.project.use.viewport?.width ?? 0) !== 375, "the phone's width, 375 px; the tablet has the press outside");
    await page.goto("/");
    const button = page.getByRole("button", { name: "القائمة" });
    await button.click();
    const dialog = page.getByRole("dialog", { name: "القائمة" });
    await settled(dialog);
    const box = (await dialog.boundingBox())!;
    expect(Math.round(box.x)).toBe(0);
    expect(Math.round(box.width)).toBe(375);
    await dialog.getByRole("button", { name: "إغلاق القائمة" }).click();
    await expect(dialog).toBeHidden();
    await expect(button).toBeFocused();
  });

  test("from 1024 px: the sections open under their item and close with Escape", async ({ page }, info) => {
    test.skip(!wide(info.project.use.viewport?.width ?? 0), "below 1024 px the menu is a dialog");
    await page.goto("/");
    await expect(page.getByRole("button", { name: "القائمة" })).toBeHidden();
    const nav = page.getByRole("navigation", { name: "القائمة الرئيسية" });
    const sections = nav.getByRole("button", { name: "أقسام رَقَم" });
    await sections.focus();
    await page.keyboard.press("Enter");
    await expect(sections).toHaveAttribute("aria-expanded", "true");
    await expect(nav.getByRole("link", { name: "البودكاست" })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(sections).toHaveAttribute("aria-expanded", "false");
    await expect(nav.getByRole("link", { name: "البودكاست" })).toBeHidden();
    await expect(sections).toBeFocused();
  });
});

test.describe("motion", () => {
  test("with reduced motion the menu only fades: no sliding", async ({ page }, info) => {
    test.skip(wide(info.project.use.viewport?.width ?? 0), "from 1024 px the menu is in the header");
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/");
    await page.getByRole("button", { name: "القائمة" }).click();
    const transition = await page.getByRole("dialog").evaluate((el) => getComputedStyle(el).transitionProperty);
    expect(transition).not.toContain("translate");
  });

  test("«للأعلى» appears down the page and goes back to the header", async ({ page }) => {
    await page.goto("/");
    const top = page.getByRole("link", { name: "للأعلى" });
    await expect(top).toBeHidden();
    await page.evaluate(() => {
      document.body.style.minHeight = "500vh";
      window.scrollTo(0, document.body.scrollHeight);
    });
    await expect(top).toBeVisible();
    await top.click();
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeLessThan(10);
  });
});
