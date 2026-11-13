import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, site, smallTargets, useTheme } from "./helpers";

/*
 * The seven sections and /news against the fake API's captured sections: the introduction, the featured item, 12 items
 * a page, filters as real links (a filtered page is noindex, canonical to the section), history on the kashida's line
 * from the oldest, awareness with its characters, and each section's RSS feed.
 */

type Item = { id: number; title: string; is_featured: boolean; facts: { year_from?: number; character?: { slug: string } } | null };
type Fixture = {
  detail: Record<string, { name: string; filters: { param: string; name: string; options: { value: string; name: string; posts_count: number }[] }[] }>;
  items: Record<string, Item[]>;
  awareness: { characters: { name: string; slug: string }[] };
  news: Item[];
};
const fixture: Fixture = JSON.parse(readFileSync("tests/fake-api/fixtures/sections-pages.json", "utf8"));
const slugs = Object.keys(fixture.detail);
const itemsOf = (slug: string) => (slug === "news" ? fixture.news : fixture.items[slug]);

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

for (const slug of [...slugs, "news"]) {
  test(`/${slug}: its name, its items, its own canonical address, structured data${slug === "news" ? "" : " and RSS"}`, async ({ page }) => {
    const response = await page.goto(`/${slug}`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(slug === "news" ? "الأخبار" : fixture.detail[slug].name);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/${slug}`);
    const graph = await graphOf(page);
    expect(graph.find((n) => n["@type"] === "CollectionPage")).toMatchObject({ url: `${site}/${slug}`, mainEntity: { "@type": "ItemList", numberOfItems: itemsOf(slug).length } });
    expect(graph.find((n) => n["@type"] === "BreadcrumbList")).toBeTruthy();
    const feed = page.locator("link[rel='alternate'][type='application/rss+xml']");
    if (slug === "news") await expect(feed).toHaveCount(0);
    else await expect(feed).toHaveAttribute("href", `${site}/feeds/sections/${slug}`);
    await expectNoSidewaysScroll(page);
  });
}

test.describe("featured item and pages", () => {
  test("the featured item opens the section and is not repeated in its list", async ({ page }) => {
    const slug = slugs.find((s) => s !== "history" && itemsOf(s).some((i) => i.is_featured))!;
    const featured = itemsOf(slug).find((i) => i.is_featured)!;
    await page.goto(`/${slug}`);
    const box = page.locator("section", { has: page.getByRole("heading", { name: "مميّز" }) });
    await expect(box.getByRole("link", { name: featured.title })).toBeVisible();
    await expect(page.getByRole("link", { name: featured.title })).toHaveCount(1);
  });

  test("12 items a page; each further page is its own canonical page; past the last, 404; /page/1, 301", async ({ page, request }) => {
    const slug = slugs.find((s) => itemsOf(s).length > 12)!;
    const last = Math.ceil(itemsOf(slug).length / 12);
    const response = await page.goto(`/${slug}/page/${last}`);
    expect(response?.status()).toBe(200);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/${slug}/page/${last}`);
    await expect(page.locator("main li article")).toHaveCount(itemsOf(slug).length - (last - 1) * 12);
    expect((await request.get(`/${slug}/page/${last + 1}`, { maxRedirects: 0 })).status()).toBe(404);
    expect((await request.get(`/${slug}/page/0`, { maxRedirects: 0 })).status()).toBe(404);
    const first = await request.get(`/${slug}/page/1`, { maxRedirects: 0 });
    expect(first.status()).toBe(301);
    expect(new URL(first.headers().location, "http://x").pathname).toBe(`/${slug}`);
  });
});

test.describe("filters", () => {
  const slug = slugs.find((s) => fixture.detail[s].filters.length > 0 && s !== "awareness")!;
  const filter = fixture.detail[slug].filters[0];
  const option = filter.options.find((o) => o.posts_count > 0)!;

  test("are real links; a filtered page lists only its items, is noindex and points to the section", async ({ page }) => {
    await page.goto(`/${slug}`);
    const nav = page.getByRole("navigation", { name: `تصفية حسب ${filter.name}` });
    await expect(nav.getByRole("link", { name: "الكل" })).toHaveAttribute("aria-current", "page");
    const link = nav.getByRole("link", { name: new RegExp(option.name) });
    await expect(link).toHaveAttribute("href", `/${slug}?${new URLSearchParams({ [filter.param]: option.value })}`);
    await link.click();
    await expect(page).toHaveURL(new RegExp(`\\?${filter.param}=`));
    await expect(nav.getByRole("link", { name: new RegExp(option.name) })).toHaveAttribute("aria-current", "page");
    await expect(page.locator("main li article")).toHaveCount(Math.min(12, option.posts_count));
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/${slug}`);
  });

  test("an unknown filter value answers 404; /news has no filters; any other query is ignored", async ({ request }) => {
    expect((await request.get(`/${slug}?${filter.param}=unknown-${Date.now()}`, { maxRedirects: 0 })).status()).toBe(404);
    expect((await request.get(`/news?season=${Date.now()}`, { maxRedirects: 0 })).status()).toBe(404);
    expect((await request.get(`/${slug}?utm_source=x${Date.now()}`, { maxRedirects: 0 })).status()).toBe(200);
  });
});

test("history hangs on the kashida's line, from the oldest", async ({ page }) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto("/history");
  const years = (await page.locator("main ol[class*='line'] li p.figures").allTextContents()).map(Number);
  const expected = fixture.items.history.slice(0, 12).map((i) => i.facts?.year_from).filter((y): y is number => typeof y === "number");
  expect(years).toEqual(expected);
  expect(years).toEqual([...years].sort((a, b) => a - b));
});

test("awareness shows its characters; each leads to the cards it has said", async ({ page }) => {
  await page.goto("/awareness");
  const characters = page.getByRole("region", { name: "الشخصيات" });
  const first = fixture.awareness.characters[0];
  await expect(characters.getByRole("link")).toHaveCount(fixture.awareness.characters.length);
  await characters.getByRole("link", { name: new RegExp(first.name) }).click();
  await expect(page).toHaveURL(new RegExp(`character=${first.slug}`));
  const said = fixture.items.awareness.filter((i) => i.facts?.character?.slug === first.slug);
  await expect(page.locator("main li article")).toHaveCount(Math.min(12, said.length));
  await expect(characters.getByRole("link", { name: "كل الشخصيات" })).toHaveAttribute("href", "/awareness");
});

test("each section's RSS feed lists its items; a hidden or unknown one is a 404", async ({ request }) => {
  for (const slug of ["podcast", "history"]) {
    const response = await request.get(`/feeds/sections/${slug}`);
    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toContain("application/rss+xml");
    expect((await response.text()).match(/<item>/g)?.length).toBe(Math.min(30, fixture.items[slug].length));
  }
  expect((await request.get(`/feeds/sections/unknown-${Date.now()}`)).status()).toBe(404);
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const path of ["/podcast", "/history", "/awareness", "/news/page/2"]) {
      await page.goto(path);
      expect(await smallTargets(page, "main"), path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
