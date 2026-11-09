import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

/*
 * The list pages (a category, a tag, a writer, a guest) against the fake API's captured lists: 12 items a page, each
 * page its own canonical address, a 404 for an unknown list, an empty tag or a page past the last.
 */

type Entity = { name: string; slug: string; job_title?: string | null };
const lists: { entities: Record<string, Record<string, Entity>>; items: Record<string, { title: string }[]> } = JSON.parse(readFileSync("tests/fake-api/fixtures/lists.json", "utf8"));
const site = "https://example.org";
const one = (kind: string) => {
  const entity = Object.values(lists.entities[kind]).find((e) => (lists.items[`${kind}:${e.slug}`] ?? []).length > 0)!;
  return { ...entity, total: lists.items[`${kind}:${entity.slug}`].length };
};
const path = (kind: string, slug: string, page = 1) => encodeURI(`/${kind}/${slug}${page > 1 ? `/page/${page}` : ""}`);

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

for (const kind of ["category", "tag", "author", "guest"]) {
  test(`a ${kind}: its name, 12 items at most, its own canonical address, breadcrumb and structured data`, async ({ page }) => {
    const list = one(kind);
    const response = await page.goto(path(kind, list.slug));
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(list.name);
    await expect(page.locator("main li article")).toHaveCount(Math.min(12, list.total));
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${path(kind, list.slug)}`);

    const graph = await graphOf(page);
    const person = graph.find((n) => n["@type"] === "Person");
    if (kind === "author" || kind === "guest") {
      expect(graph.find((n) => n["@type"] === "ProfilePage")).toMatchObject({ mainEntity: { "@id": person!["@id"] } });
      expect(person).toMatchObject({ name: list.name, url: `${site}${path(kind, list.slug)}` });
      if (list.job_title) expect(person!.jobTitle).toBe(list.job_title);
    } else {
      expect(graph.find((n) => n["@type"] === "CollectionPage")).toMatchObject({ url: `${site}${path(kind, list.slug)}`, mainEntity: { "@type": "ItemList", numberOfItems: list.total } });
      expect(person).toBeUndefined();
    }
    const crumbs = graph.find((n) => n["@type"] === "BreadcrumbList") as { itemListElement: { name: string }[] };
    expect(crumbs.itemListElement.map((c) => c.name)).toEqual(["الرئيسية", kind === "tag" ? `وسم: ${list.name}` : list.name]);
    await expectNoSidewaysScroll(page);
  });
}

test.describe("pages of a list", () => {
  test("each further page is its own canonical page; the navigation marks where you are", async ({ page }) => {
    const author = one("author");
    const last = Math.ceil(author.total / 12);
    const response = await page.goto(path("author", author.slug, 2));
    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle(new RegExp(`صفحة 2`));
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${path("author", author.slug, 2)}`);
    const nav = page.getByRole("navigation", { name: "صفحات القائمة" });
    await expect(nav.locator("[aria-current='page']")).toHaveText(/2/);
    await expect(nav.getByRole("link", { name: "السابق" })).toHaveAttribute("href", `/author/${author.slug}`);
    await expect(nav.getByRole("link", { name: "التالي" })).toHaveAttribute("href", `/author/${author.slug}/page/3`);
    // The last page holds what is left.
    await page.goto(path("author", author.slug, last));
    await expect(page.locator("main li article")).toHaveCount(author.total - (last - 1) * 12);
    await expect(nav.getByRole("link", { name: "التالي" })).toHaveCount(0);
  });

  test("a page past the last, /page/0 and /page/abc answer 404; /page/1 answers 301 to the list itself", async ({ request }) => {
    const category = one("category");
    const last = Math.ceil(category.total / 12);
    for (const wrong of [path("category", category.slug, last + 1), `${path("category", category.slug)}/page/0`, `${path("category", category.slug)}/page/abc`, `${path("category", category.slug)}/x`]) {
      expect((await request.get(wrong, { maxRedirects: 0 })).status(), wrong).toBe(404);
    }
    const first = await request.get(`${path("category", category.slug)}/page/1`, { maxRedirects: 0 });
    expect(first.status()).toBe(301);
    expect(decodeURI(new URL(first.headers().location, "http://x").pathname)).toBe(`/category/${category.slug}`);
  });

  test("an unknown category, tag, writer or guest answers 404, and a tag no live item carries too", async ({ request, page }) => {
    for (const kind of ["category", "tag", "author", "guest"]) {
      expect((await request.get(`/${kind}/unknown-${Date.now()}`, { maxRedirects: 0 })).status(), kind).toBe(404);
    }
    await page.goto(`/tag/unknown-${Date.now()}`);
    await expect(page.getByRole("heading", { level: 1, name: "الصفحة غير موجودة" })).toBeVisible();
  });

  test("a category with nothing in it yet says so", async ({ page }) => {
    const response = await page.goto(path("category", "تصنيف-فارغ"));
    expect(response?.status()).toBe(200);
    await expect(page.getByText("لا مواد هنا بعد.")).toBeVisible();
  });
});

test.describe("a category's feed", () => {
  test("the page names its RSS feed, which lists the category's latest items", async ({ page, request }) => {
    const category = one("category");
    await page.goto(path("category", category.slug));
    const feed = page.locator("link[rel='alternate'][type='application/rss+xml']");
    await expect(feed).toHaveAttribute("href", `${site}${encodeURI(`/feeds/categories/${category.slug}`)}`);
    const response = await request.get(encodeURI(`/feeds/categories/${category.slug}`));
    expect(response.status()).toBe(200);
    expect(response.headers()["content-type"]).toContain("application/rss+xml");
    const xml = await response.text();
    expect(xml).toMatch(/^<\?xml version="1.0" encoding="UTF-8"\?>\n<rss version="2.0"/);
    expect(xml.match(/<item>/g)?.length).toBe(Math.min(30, category.total));
    expect(xml).toContain(`<atom:link href="${site}${encodeURI(`/feeds/categories/${category.slug}`)}" rel="self"`);
    expect((await request.get(`/feeds/categories/unknown-${Date.now()}`)).status()).toBe(404);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const kind of ["category", "author"]) {
      const list = one(kind);
      await page.goto(path(kind, list.slug, kind === "author" ? 2 : 1));
      expect(await smallTargets(page, "main"), kind).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
