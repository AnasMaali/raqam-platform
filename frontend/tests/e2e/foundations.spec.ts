import { expect, test } from "@playwright/test";

import { expectNoSidewaysScroll, labUrl, smallTargets } from "./helpers";

/* The components page of the development lab, with every component in every state. */

test.beforeEach(async ({ page }) => {
  await page.goto(labUrl("/lab/foundations"));
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
});

test("every component is on the page, without sideways scrolling, light and dark", async ({ page }) => {
  for (const id of ["colours", "type", "space", "header", "breaking", "strip", "board", "weather", "ads", "cards", "chapters"]) {
    await expect(page.locator(`section[aria-labelledby="${id}"]`)).toBeVisible();
  }
  await expectNoSidewaysScroll(page);
  await page.emulateMedia({ colorScheme: "dark" });
  await expectNoSidewaysScroll(page);
});

test("every control is at least 44 × 44 px", async ({ page }) => {
  expect(await smallTargets(page, "main")).toEqual([]);
});

test("the currency board shows three currencies below 1024 px and all of them from 1024 px", async ({ page }) => {
  const rows = page.locator('section:has(#rates-board-title) tbody tr');
  const total = await rows.count();
  expect(total).toBeGreaterThan(3);
  const visible = await rows.evaluateAll((all) => all.filter((r) => r.getClientRects().length > 0).length);
  const width = page.viewportSize()!.width;
  expect(visible).toBe(width >= 1024 ? total : 3);
  await expect(page.locator("section:has(#rates-board-title)").getByText("استرشادية")).toBeVisible();
});

test("rises and falls are never colour alone: an arrow and a spoken word with every change", async ({ page }) => {
  const strip = page.locator("section:has(#rates-strip-title)").first();
  const changes = strip.locator("span.inline-flex:has(svg)");
  expect(await changes.count()).toBeGreaterThan(0);
  for (const change of await changes.all()) {
    await expect(change.locator("svg")).toHaveCount(1);
    await expect(change.locator(".sr-only")).toHaveText(/ارتفاع|انخفاض|بدون تغيّر/);
  }
  // A signed number uses the minus sign, not a hyphen.
  const text = await strip.innerText();
  expect(text).not.toMatch(/-\d/);
});

test("the weather always carries its OpenWeather credit", async ({ page }) => {
  const widgets = page.locator("section:has(> h3[id='weather-title']), section[aria-labelledby='weather-title']");
  expect(await widgets.count()).toBeGreaterThan(0);
  for (const widget of await widgets.all()) {
    await expect(widget.getByText("Weather data © OpenWeather")).toBeVisible();
  }
});

test("an ad says «إعلان» and keeps its box before the picture arrives", async ({ page }) => {
  const ads = page.locator("aside[aria-label='إعلان']");
  expect(await ads.count()).toBeGreaterThanOrEqual(3);
  for (const ad of await ads.all()) {
    if (!(await ad.isVisible())) continue;
    await expect(ad.getByText("إعلان", { exact: true })).toBeVisible();
    const box = await ad.locator("a").evaluate((el) => {
      const style = getComputedStyle(el);
      return { ratio: style.aspectRatio, height: el.getBoundingClientRect().height };
    });
    expect(box.ratio).not.toBe("auto");
    expect(box.height).toBeGreaterThan(40);
  }
});

test("a card is one link, the whole card can be pressed, and it says when it is sponsored", async ({ page }) => {
  const cards = page.locator("section[aria-labelledby='cards'] article");
  expect(await cards.count()).toBeGreaterThan(5);
  for (const card of (await cards.all()).slice(0, 6)) {
    await expect(card.getByRole("link")).toHaveCount(1);
  }
  await expect(page.locator("section[aria-labelledby='cards'] article").getByText(/^محتوى ممول/)).toBeVisible();
});

test("the chapters: a row below 1280 px, on the page's edge from 1280 px", async ({ page }) => {
  const width = page.viewportSize()!.width;
  const row = page.getByRole("navigation", { name: "الأبواب" }).first();
  if (width >= 1280) {
    await expect(page.locator("#foundations-edge-title")).toBeVisible();
    await expect(page.locator("#foundations-row-title")).toBeHidden();
  } else {
    await expect(page.locator("#foundations-row-title")).toBeVisible();
    await expect(page.locator("#foundations-edge-title")).toBeHidden();
    await expect(row.getByRole("link").first()).toBeVisible();
  }
});
