import { expect, test, type APIRequestContext, type Locator, type Page } from "@playwright/test";

import { fakeApi, smallTargets } from "./helpers";

/*
 * The two floating ads (13.2), on in the fake API with one made-up campaign each (tests/fake-api/server.mjs): the
 * popup from 1024 px only, opened by the visitor's first interaction and at most once in its frequency (24 hours); the
 * bar at the bottom of a phone's screen, drawn at the first interaction, below the raised buttons and the footer's end.
 * Neither's code loads before the page has loaded, neither is ever the page's largest paint (LCP), and neither moves
 * anything. Every other test starts as a visitor who has just seen the popup
 * (playwright.config.ts); these start with an empty browser.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const popup = (page: Page) => page.locator("dialog[data-ad-popup]");
const bar = (page: Page) => page.locator("[data-ad-bar-box]");
const launcher = (page: Page) => page.locator("[data-chat-launcher]");
const shownKey = "raqam:ad-popup";
const hours = 3_600_000;
// Only each one's own code carries these.
const markers = { popup: "raqam:ad-popup", bar: "data-ad-bar-box" };

const isWide = (page: Page) => page.viewportSize()!.width >= 1024;
const isPhone = (page: Page) => page.viewportSize()!.width < 768;

/** Every script the page loads, with its body, and whether one was asked for after the page's load event. */
function recordScripts(page: Page) {
  const scripts: { url: string; body: string }[] = [];
  page.on("response", async (r) => {
    if (r.request().resourceType() === "script") scripts.push({ url: r.url(), body: await r.text().catch(() => "") });
  });

  return {
    holding: (marker: string) => scripts.filter((s) => s.body.includes(marker)).map((s) => s.url),
    afterLoad: (url: string) =>
      page.evaluate((u) => {
        const load = (performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming).loadEventStart;
        const asked = performance.getEntriesByName(u)[0];
        return !!asked && load > 0 && asked.startTime >= load;
      }, url),
  };
}

/** What the fake platform was told this browser saw in one slot. */
async function seenIn(request: APIRequestContext, agent: string, placement: string) {
  const { impressions } = (await (await request.get(`${fakeApi}/__impressions`)).json()) as {
    impressions: { items: { campaign_id: number; placement: string }[]; visitor: { ua: string; token: boolean } }[];
  };

  return impressions.filter((i) => i.visitor.ua === agent).flatMap((i) => i.items.filter((item) => item.placement === placement).map((item) => ({ ...item, token: i.visitor.token })));
}

async function arrive(page: Page) {
  await page.goto("/");
  await page.waitForLoadState("load");
}

const popupLoaded = (page: Page) => expect.poll(() => page.evaluate(() => !!document.querySelector("dialog[data-ad-popup]"))).toBe(true);

/** A scroll, the visitor's most common first interaction. */
async function scrollABit(page: Page) {
  await page.mouse.wheel(0, 300);
  await page.evaluate(() => window.scrollBy(0, 300));
}

/** Scrolls a little until `shown` appears: a scroll before the page's own code is ready is not seen, as for a visitor. */
async function scrollUntil(page: Page, shown: Locator) {
  await expect(async () => {
    await scrollABit(page);
    await expect(shown).toBeVisible({ timeout: 1500 });
  }).toPass({ timeout: 15_000 });
}

/** A press on the page's edge, where there is nothing to follow. */
const pressThePage = (page: Page) => page.mouse.click(2, 300);

test.describe("the popup", () => {
  test("below 1024 px there is no popup, whatever the visitor does, and its code never loads", async ({ page }) => {
    test.skip(isWide(page), "phone and tablet");
    const scripts = recordScripts(page);
    await arrive(page);
    await scrollABit(page);
    await page.keyboard.press("ArrowDown");
    await pressThePage(page);
    await page.waitForTimeout(2000);
    await expect(popup(page)).toHaveCount(0);
    expect(scripts.holding(markers.popup)).toEqual([]);
    expect(await page.evaluate((key) => localStorage.getItem(key), shownKey)).toBeNull();
  });

  test("at 1440 it loads after the page, waits for the first interaction, opens once and says «إعلان»", async ({ page }) => {
    test.skip(!isWide(page), "desktop");
    const scripts = recordScripts(page);
    await arrive(page);
    await expect.poll(() => scripts.holding(markers.popup).length).toBe(1);
    expect(await scripts.afterLoad(scripts.holding(markers.popup)[0])).toBe(true);
    // Loaded, and still closed with nothing done.
    await page.waitForTimeout(2000);
    await expect(popup(page)).toHaveCount(1);
    await expect(popup(page)).toBeHidden();

    await scrollUntil(page, popup(page));
    await expect(popup(page).getByText("إعلان", { exact: true })).toBeVisible();
    await expect(popup(page).getByRole("button", { name: "إغلاق الإعلان" })).toBeFocused();
    const link = popup(page).getByRole("link", { name: "إعلان: شركة تجريبية للمنبثق" });
    await expect(link).toHaveAttribute("href", `${fakeApi}/api/v1/ads/click/9001/popup/fake-signature`);
    await expect(link).toHaveAttribute("rel", "sponsored noopener");
    // Measured once the opening (a slight scale) has finished.
    await popup(page).evaluate((dialog) => Promise.all(dialog.getAnimations({ subtree: true }).map((a) => a.finished)));
    expect(await smallTargets(page, "dialog[data-ad-popup]")).toEqual([]);
    const shown = Number(await page.evaluate((key) => localStorage.getItem(key), shownKey));
    expect(Math.abs(Date.now() - shown)).toBeLessThan(60_000);

    await page.keyboard.press("Escape");
    await expect(popup(page)).toBeHidden();
    await page.waitForTimeout(500);
    await scrollABit(page);
    await pressThePage(page);
    await page.waitForTimeout(1500);
    await expect(popup(page)).toBeHidden();
  });

  test("at 1440 the frequency keeps it closed for 24 hours, in this browser", async ({ page }) => {
    test.skip(!isWide(page), "desktop");
    await arrive(page);
    for (const [ago, opens] of [
      [23 * hours, false],
      [24 * hours + 60_000, true],
    ] as const) {
      await page.evaluate(([key, at]) => localStorage.setItem(key, String(at)), [shownKey, Date.now() - ago] as const);
      await arrive(page);
      await popupLoaded(page);
      if (opens) await scrollUntil(page, popup(page));
      else {
        await scrollABit(page);
        await pressThePage(page);
        await page.waitForTimeout(1500);
        await expect(popup(page)).toBeHidden();
      }
    }
  });

  test("at 1440 it never opens over the chat: a press on the chat's button opens the chat, and the ad waits", async ({ page }) => {
    test.skip(!isWide(page), "desktop");
    await arrive(page);
    await popupLoaded(page);
    await launcher(page).click();
    await expect(page.getByRole("dialog", { name: "المساعد" })).toBeVisible();
    await page.waitForTimeout(1500);
    await expect(popup(page)).toBeHidden();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog", { name: "المساعد" })).toBeHidden();
    await scrollUntil(page, popup(page));
  });

  test.describe(() => {
    // A browser of its own, so this test reads its own reports among the others'.
    const agent = `Mozilla/5.0 (raqam-test ads-popup ${Date.now()}-${Math.random()})`;
    test.use({ userAgent: agent });

    test("at 1440 its view is counted once it opens, through the site's route, and not before", async ({ page, request }) => {
      test.skip(!isWide(page), "desktop");
      await arrive(page);
      await popupLoaded(page);
      await page.waitForTimeout(3000);
      expect(await seenIn(request, agent, "popup")).toEqual([]);
      await scrollUntil(page, popup(page));
      await expect.poll(() => seenIn(request, agent, "popup")).toEqual([{ campaign_id: 9001, placement: "popup", token: true }]);
    });
  });
});

test.describe("the phone's bar", () => {
  test.describe(() => {
    const agent = `Mozilla/5.0 (raqam-test ads-bar ${Date.now()}-${Math.random()})`;
    test.use({ userAgent: agent });

    test("below 768 px: drawn at the first interaction, at the bottom, says «إعلان», and is counted through the site's route", async ({ page, request }) => {
      test.skip(!isPhone(page), "phone");
      const scripts = recordScripts(page);
      await arrive(page);
      // Nothing done: no bar and none of its code.
      await page.waitForTimeout(2500);
      await expect(bar(page)).toHaveCount(0);
      expect(scripts.holding(markers.bar)).toEqual([]);
      await scrollUntil(page, bar(page));
      expect(scripts.holding(markers.bar)).toHaveLength(1);
      expect(await scripts.afterLoad(scripts.holding(markers.bar)[0])).toBe(true);

      const box = (await bar(page).boundingBox())!;
      const viewport = page.viewportSize()!;
      expect([box.x, box.y + box.height, box.width, box.height]).toEqual([0, viewport.height, viewport.width, 58]);
      await expect(bar(page).getByText("إعلان", { exact: true })).toBeVisible();
      const link = bar(page).getByRole("link", { name: "إعلان: شركة تجريبية للشريط" });
      await expect(link).toHaveAttribute("href", `${fakeApi}/api/v1/ads/click/9002/mobile_sticky/fake-signature`);
      await expect(link).toHaveAttribute("rel", "sponsored noopener");
      expect(await smallTargets(page, "[data-ad-bar-box]")).toEqual([]);
      await expect.poll(() => seenIn(request, agent, "mobile_sticky")).toEqual([{ campaign_id: 9002, placement: "mobile_sticky", token: true }]);
    });
  });

  test("below 768 px a scroll while the page is still loading draws the bar only after the load", async ({ page }) => {
    test.skip(!isPhone(page), "phone");
    // The pictures held back, so the page is still loading while the visitor scrolls.
    await page.route("**/media/**", async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 4000));
      await route.continue();
    });
    const scripts = recordScripts(page);
    await page.goto("/", { waitUntil: "domcontentloaded" });
    for (let i = 0; i < 6; i++) {
      await page.waitForTimeout(300);
      await page.evaluate(() => window.scrollBy(0, 40));
    }
    expect(await page.evaluate(() => document.readyState), "the scrolls came before the load").not.toBe("complete");
    await page.waitForLoadState("load");
    await expect(bar(page)).toBeVisible({ timeout: 10_000 });
    expect(scripts.holding(markers.bar)).toHaveLength(1);
    expect(await scripts.afterLoad(scripts.holding(markers.bar)[0])).toBe(true);
  });

  test("below 768 px it covers neither the chat's button, nor «للأعلى», nor the footer's end", async ({ page }) => {
    test.skip(!isPhone(page), "phone");
    await arrive(page);
    await scrollUntil(page, bar(page));
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    const top = page.getByRole("link", { name: "للأعلى" });
    await expect(top).toBeVisible();
    await page.waitForTimeout(300);
    const barTop = (await bar(page).boundingBox())!.y;
    for (const button of [launcher(page), top]) {
      const b = (await button.boundingBox())!;
      expect(b.y + b.height, "a button above the bar").toBeLessThanOrEqual(barTop - 8);
    }
    const footerEnd = await page.locator("footer").evaluate((f) => f.getBoundingClientRect().bottom);
    expect(footerEnd, "the footer's end above the bar").toBeLessThanOrEqual(barTop + 1);
  });

  test("from 768 px there is no bar, nothing kept for it, and its code never loads", async ({ page, request }) => {
    test.skip(isPhone(page), "tablet and desktop");
    // The server keeps the place on every width; only a phone's width uses it.
    expect(await (await request.get("/")).text()).toContain('data-ad-bar="" style="--ad-bar-h:58px"');
    const scripts = recordScripts(page);
    await arrive(page);
    await scrollABit(page);
    await pressThePage(page);
    await page.waitForTimeout(2500);
    await expect(bar(page)).toHaveCount(0);
    expect(scripts.holding(markers.bar)).toEqual([]);
    expect(await page.evaluate(() => getComputedStyle(document.body).paddingBottom)).toBe("0px");
    const b = (await launcher(page).boundingBox())!;
    expect(page.viewportSize()!.height - (b.y + b.height)).toBe(16);
  });
});

test("the bar's arrival, the popup opening and closing move nothing on the page (CLS 0 after the load)", async ({ page }) => {
  await page.addInitScript(() => {
    const record = window as unknown as { shifted: number; moved: string[] };
    record.shifted = 0;
    record.moved = [];
    new PerformanceObserver((list) => {
      const load = (performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming | undefined)?.loadEventStart || Infinity;
      for (const entry of list.getEntries() as (PerformanceEntry & { value: number; sources?: { node?: Node }[] })[]) {
        // Every ad's code runs after the load event, so only what moves from then on can be theirs.
        if (entry.startTime < load) continue;
        record.shifted += entry.value;
        for (const source of entry.sources ?? []) {
          const node = source.node as HTMLElement | undefined;
          record.moved.push(`${node?.nodeName ?? "?"}.${String(node?.className ?? "").slice(0, 60)} at ${Math.round(entry.startTime)} ms`);
        }
      }
    }).observe({ type: "layout-shift", buffered: true });
  });
  await arrive(page);
  if (isPhone(page)) {
    await scrollUntil(page, bar(page));
  }
  if (isWide(page)) {
    await popupLoaded(page);
    await scrollUntil(page, popup(page));
    await page.keyboard.press("Escape");
    await expect(popup(page)).toBeHidden();
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(2500);
  const { shifted, moved } = await page.evaluate(() => {
    const record = window as unknown as { shifted: number; moved: string[] };
    return { shifted: record.shifted, moved: record.moved };
  });
  expect(shifted, moved.join("; ")).toBe(0);
});

test("neither is ever the page's largest paint (LCP), even on a page whose largest paint is text", async ({ page }) => {
  await page.addInitScript(() => {
    const record = window as unknown as { largest: string[] };
    record.largest = [];
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries() as (PerformanceEntry & { element?: Element | null })[])
        record.largest.push(entry.element?.closest("[data-ad-bar-box], dialog[data-ad-popup]") ? "ad" : (entry.element?.nodeName ?? "?"));
    }).observe({ type: "largest-contentful-paint", buffered: true });
  });
  // At the phone width Lighthouse uses, /faq's largest paint (a link's text) is smaller than the bar's picture.
  if (isPhone(page)) await page.setViewportSize({ width: 412, height: 823 });
  await page.goto("/faq");
  await page.waitForLoadState("load");
  await page.waitForTimeout(2500);
  if (isPhone(page)) await scrollUntil(page, bar(page));
  if (isWide(page)) await scrollUntil(page, popup(page));
  if (!isPhone(page) && !isWide(page)) await scrollABit(page);
  await page.waitForTimeout(1000);
  const largest = await page.evaluate(() => (window as unknown as { largest: string[] }).largest);
  expect(largest.length).toBeGreaterThan(0);
  expect(largest).not.toContain("ad");
});
