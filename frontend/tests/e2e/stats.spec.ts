import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext } from "@playwright/test";

import { fakeApi } from "./helpers";

/*
 * The site's own statistics (decision 22) against the fake API, which keeps what the site's server reported: the page
 * view after the page loaded (or the missing page), with the post's id worked out on the server, the visitor's address
 * and browser, no cookie; a new view on a client navigation; the ads seen, once each, when half on screen.
 */

type Hit = { type: string; path: string; referrer: string | null; post_id: number | null; visitor: { ip: string | null; ua: string | null; token: boolean } };
type Impression = { view_id: string; items: { campaign_id: number; placement: string }[]; visitor: { ip: string | null; ua: string | null; token: boolean } };

const posts: { data: { id: number; path: string; kind: { code: string } }[] } = JSON.parse(readFileSync("tests/fake-api/fixtures/posts.json", "utf8"));
const news = posts.data.find((p) => p.kind.code === "news")!;
const unique = () => `${Date.now()}${Math.random().toString(36).slice(2, 8)}`;

const hits = async (request: APIRequestContext) => ((await (await request.get(`${fakeApi}/__hits`)).json()) as { hits: Hit[] }).hits;
const impressions = async (request: APIRequestContext) => ((await (await request.get(`${fakeApi}/__impressions`)).json()) as { impressions: Impression[] }).impressions;
const hitFor = async (request: APIRequestContext, marker: string) => (await hits(request)).find((h) => h.path.includes(marker));

test.describe("page views", () => {
  // A browser of its own, so this test reads its own reports among the others'.
  const agent = `Mozilla/5.0 (raqam-views ${unique()})`;
  test.use({ userAgent: agent });
  const mine = async (request: APIRequestContext) => (await hits(request)).filter((h) => h.visitor.ua === agent);

  test("a page view is reported after the page loaded, through the site's server: the post's id from the address, the visitor, no cookie", async ({ page, request, context }) => {
    // When the report leaves, against when the page finished loading, both on the page's own clock.
    await page.addInitScript(() => {
      const original = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = (url, data) => {
        if (String(url).endsWith("/api/stats")) (window as unknown as { reportedAt?: number }).reportedAt ??= performance.now();
        return original(url, data);
      };
    });
    const marker = unique();
    await page.goto(`${encodeURI(news.path)}?utm_source=t${marker}`);
    await expect.poll(async () => (await mine(request)).find((h) => h.path.includes(marker))).toBeTruthy();
    const hit = (await mine(request)).find((h) => h.path.includes(marker))!;
    expect(hit).toMatchObject({ type: "view", post_id: news.id, referrer: null });
    expect(decodeURI(hit.path)).toBe(`${news.path}?utm_source=t${marker}`);
    expect(hit.visitor.token).toBe(true);
    expect(hit.visitor.ip).toBeTruthy();
    const { reportedAt, loaded } = await page.evaluate(() => ({
      reportedAt: (window as unknown as { reportedAt?: number }).reportedAt ?? -1,
      loaded: (performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming).loadEventEnd,
    }));
    expect(loaded).toBeGreaterThan(0);
    expect(reportedAt).toBeGreaterThanOrEqual(loaded);
    expect(await context.cookies()).toEqual([]);
  });

  test("a missing page is reported as one; each client navigation is a new view", async ({ page, request }) => {
    const marker = unique();
    await page.goto(`/no-such-page-${marker}`);
    await expect.poll(async () => (await mine(request)).find((h) => h.path.includes(marker))?.type).toBe("not_found");

    await page.goto(`/currencies?v=${marker}`);
    await expect.poll(async () => (await mine(request)).some((h) => h.path.includes(`v=${marker}`))).toBe(true);
    await page.getByRole("main").getByRole("link", { name: /دولار أمريكي/ }).first().click();
    await expect(page).toHaveURL(/\/currencies\/usd/);
    await expect.poll(async () => (await mine(request)).filter((h) => h.path === "/currencies/usd").length).toBe(1);
  });
});

test.describe("ads", () => {
  // A browser of its own, so this test reads its own reports among the others' (a beacon's body is not visible to the
  // test; the fake platform keeps it with the visitor's browser).
  const agent = `Mozilla/5.0 (raqam-test ${unique()})`;
  test.use({ userAgent: agent });

  test("an ad is reported once, when half of it is on screen, through the site's server", async ({ page, request }) => {
    const mine = async () => (await impressions(request)).filter((i) => i.visitor.ua === agent).flatMap((i) => i.items.map((item) => ({ ...item, view: i.view_id, token: i.visitor.token })));
    await page.goto(encodeURI(news.path));
    const ad = page.locator("[data-ad-placement='in_article']");
    await expect(ad).toHaveCount(1);
    // Not yet on screen: not reported.
    await page.waitForTimeout(2000);
    expect((await mine()).some((i) => i.placement === "in_article")).toBe(false);
    await ad.scrollIntoViewIfNeeded();
    await expect.poll(async () => (await mine()).filter((i) => i.placement === "in_article").length).toBe(1);
    // Away and back again: still once in this view.
    await page.evaluate(() => window.scrollTo(0, 0));
    await ad.scrollIntoViewIfNeeded();
    await page.waitForTimeout(2000);
    const seen = (await mine()).filter((i) => i.placement === "in_article");
    expect(seen).toHaveLength(1);
    expect(seen[0]).toMatchObject({ campaign_id: 3, token: true });
    expect(seen[0].view).toMatch(/^[A-Za-z0-9_-]{8,64}$/);
  });
});

test("the routes take only the site's own pages and their own shapes; the browser cannot name a post", async ({ request, baseURL }) => {
  const origin = new URL(baseURL!).origin;
  const marker = unique();
  const post = (data: unknown, from = origin) => request.post("/api/stats", { data: Buffer.from(JSON.stringify(data)), headers: { Origin: from, "Content-Type": "text/plain" } });
  expect((await post({ type: "view", path: `/elsewhere-${marker}` }, "https://elsewhere.example")).status()).toBe(204);
  expect((await post({ type: "event", path: `/event-${marker}` })).status()).toBe(204);
  expect((await post({ type: "view", path: `//evil-${marker}` })).status()).toBe(204);
  expect((await post({ type: "view", path: `/x-${marker}`, post_id: 999 })).status()).toBe(204);
  await expect.poll(() => hitFor(request, `x-${marker}`)).toBeTruthy();
  const all = await hits(request);
  expect(all.some((h) => h.path.includes(`elsewhere-${marker}`) || h.path.includes(`event-${marker}`) || h.path.includes(`evil-${marker}`))).toBe(false);
  expect(all.find((h) => h.path.includes(`x-${marker}`))!.post_id).toBeNull();
  const ads = await request.post("/api/ads/impressions", { data: Buffer.from(JSON.stringify({ view_id: "short", items: [{ campaign_id: 1, placement: "x" }] })), headers: { Origin: origin } });
  expect(ads.status()).toBe(204);
});
