import { expect, test } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

const wide = (width: number) => width >= 1024;

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

  test("on phones: a press outside the sheet closes it", async ({ page }, info) => {
    test.skip(wide(info.project.use.viewport?.width ?? 0), "from 1024 px the menu is in the header");
    await page.goto("/");
    await page.getByRole("button", { name: "القائمة" }).click();
    const dialog = page.getByRole("dialog", { name: "القائمة" });
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    const viewport = page.viewportSize()!;
    // The sheet is on the right; the backdrop is on the left, if the screen is wider than the sheet.
    test.skip(!box || box.x < 20, "the sheet covers the whole width");
    await page.mouse.click(Math.max(5, box!.x / 2), viewport.height / 2);
    await expect(dialog).toBeHidden();
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
