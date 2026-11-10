import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

/*
 * The video library, one video, a playlist, and an awareness character's page, against the fake API's captured
 * library (capture-video.mjs): 12 a page with its own addresses, the section filter (noindex, pointing to the library),
 * the light player that loads YouTube only on press, the chapters, the platform's VideoObject, and the statuses from
 * the first visit.
 */

type V = { id: number; path: string; title: string; chapters: { start: number; title: string }[] };
const fixture: {
  library: Record<string, { data: V[]; meta: { total: number; last_page: number } }>;
  playlists: { data: { slug: string; name: string }[] };
  playlist: Record<string, { data: { name: string; videos: V[] } }>;
  video: Record<string, { data: V }>;
  character: Record<string, { detail: { data: { name: string; slug: string } }; cards: { data: { title: string }[]; meta: { total: number } } }>;
} = JSON.parse(readFileSync("tests/fake-api/fixtures/video.json", "utf8"));
const site = "https://example.org";
const first = fixture.library["page=1"];
const withChapters = Object.values(fixture.video).find((v) => v.data.chapters.length > 1)!.data;
const playlist = Object.values(fixture.playlist)[0].data;
const playlistSlug = Object.keys(fixture.playlist)[0];
const character = Object.values(fixture.character)[0];

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

test.describe("/video", () => {
  test("the playlists, the sections as filters, 12 videos a page, its own address, CollectionPage", async ({ page }) => {
    const response = await page.goto("/video");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("مكتبة الفيديو");
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/video`);
    await expect(page.getByRole("region", { name: "قوائم التشغيل" }).getByRole("link")).toHaveCount(fixture.playlists.data.length);
    await expect(page.locator("main ul.grid > li article")).toHaveCount(first.data.length);
    await expect(page.getByRole("link", { name: first.data[0].title })).toHaveAttribute("href", first.data[0].path);
    expect((await graphOf(page)).find((n) => n["@type"] === "CollectionPage")).toMatchObject({ mainEntity: { numberOfItems: first.meta.total } });
    await page.getByRole("navigation", { name: "تصفية حسب القسم" }).getByRole("link", { name: "البودكاست" }).click();
    await expect(page).toHaveURL(/section=podcast/);
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/video`);
    await expect(page.locator("main ul.grid > li article")).toHaveCount(fixture.library["section=podcast"].data.length);
    await expectNoSidewaysScroll(page);
  });

  test("a further page is its own canonical page; past the last, an unknown section and /page/1 answer before the page", async ({ page, request }) => {
    const response = await page.goto("/video/page/2");
    expect(response?.status()).toBe(200);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/video/page/2`);
    expect((await request.get(`/video/page/${first.meta.last_page + 5}`, { maxRedirects: 0 })).status()).toBe(404);
    expect((await request.get("/video?section=no-such-section", { maxRedirects: 0 })).status()).toBe(404);
    const moved = await request.get("/video/page/1", { maxRedirects: 0 });
    expect(moved.status()).toBe(301);
    expect(new URL(moved.headers().location, "http://x").pathname).toBe("/video");
  });
});

test.describe("/video/{id}", () => {
  test("a light player that loads YouTube only on press, chapters that start there, the platform's VideoObject", async ({ page }) => {
    const asked: string[] = [];
    await page.route("https://www.youtube-nocookie.com/**", (route) => {
      asked.push(route.request().url());
      return route.fulfill({ contentType: "text/html", body: "<html></html>" });
    });
    const response = await page.goto(withChapters.path);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(withChapters.title);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${withChapters.path}`);
    await page.waitForLoadState("networkidle");
    expect(asked).toEqual([]);
    const chapter = withChapters.chapters[1];
    await page.getByRole("navigation", { name: "فصول الفيديو" }).getByRole("button", { name: new RegExp(chapter.title) }).click();
    await expect(page.locator("iframe[src*='youtube-nocookie.com']")).toHaveAttribute("src", new RegExp(`start=${chapter.start}`));
    const video = (await graphOf(page)).find((n) => n["@type"] === "VideoObject") as { name: string; hasPart?: unknown[] };
    expect(video.name).toBe(withChapters.title);
    expect(video.hasPart?.length).toBe(withChapters.chapters.length);
    await expectNoSidewaysScroll(page);
  });

  test("an unknown video is a 404 from the first visit; a word is no video", async ({ request }) => {
    for (const path of ["/video/999999999", "/video/abc", "/video/0"]) expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
  });
});

test.describe("/video/playlists/{slug}", () => {
  test("its videos in order", async ({ page, request }) => {
    const response = await page.goto(encodeURI(`/video/playlists/${playlistSlug}`));
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(playlist.name);
    const items = page.locator("main ol.grid > li");
    await expect(items).toHaveCount(playlist.videos.length);
    await expect(items.first().getByRole("link")).toHaveText(playlist.videos[0].title);
    expect((await graphOf(page)).find((n) => n["@type"] === "CollectionPage")).toMatchObject({ mainEntity: { itemListOrder: "https://schema.org/ItemListOrderAscending" } });
    expect((await request.get("/video/playlists/no-such-playlist", { maxRedirects: 0 })).status()).toBe(404);
  });
});

test.describe("/awareness/characters/{slug}", () => {
  test("who the character is and the cards it has said; an unknown one is a 404", async ({ page, request }) => {
    const { detail, cards } = character;
    const response = await page.goto(`/awareness/characters/${detail.data.slug}`);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(detail.data.name);
    await expect(page.locator("main ul > li article")).toHaveCount(cards.data.length);
    expect((await graphOf(page)).find((n) => n["@type"] === "BreadcrumbList")).toMatchObject({ itemListElement: [{ name: "الرئيسية" }, { name: "التوعية" }, { name: detail.data.name }] });
    expect((await request.get("/awareness/characters/no-such-character", { maxRedirects: 0 })).status()).toBe(404);
    await expectNoSidewaysScroll(page);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const path of ["/video", withChapters.path, encodeURI(`/video/playlists/${playlistSlug}`), `/awareness/characters/${character.detail.data.slug}`]) {
      await page.goto(path);
      expect(await smallTargets(page, "main"), path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
