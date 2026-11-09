import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

/*
 * The home page (the directory): the first screen, the chapters in the admin's order, each block in its own shape, the
 * ad and the newsletter, and what search engines read. Content comes from the fake API's fixtures.
 */

type Block = { id: number; type: string; title: string; category?: { name: string }; section?: { key: string; name: string }; posts?: { id: number; title: string; facts?: { year_from?: number } | null }[] };
const blocks: Block[] = JSON.parse(readFileSync("tests/fake-api/fixtures/home.json", "utf8")).data;
const lead = blocks.find((b) => b.type === "hero")!.posts![0];
const chapterNames = blocks.filter((b) => b.type === "category" || b.type === "section").map((b) => b.category?.name ?? b.section!.name);

const wide = (width: number) => width >= 1024;
const chapter = (page: Page, name: string) => page.locator("main section[id^='bab-']").filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });

test.describe("the first screen", () => {
  test("holds the main story, and the currency board beside it (below it on phones and tablets)", async ({ page }) => {
    await page.goto("/");
    const story = page.getByRole("heading", { level: 2, name: lead.title });
    const board = page.getByRole("heading", { name: "أسعار العملات" });
    await expect(story).toBeInViewport();
    const s = (await story.boundingBox())!;
    const b = (await board.boundingBox())!;
    if (wide(page.viewportSize()!.width)) {
      // Beside it: on the end side (left) and in the first screen.
      expect(b.x + b.width).toBeLessThan(s.x);
      await expect(board).toBeInViewport();
    } else {
      expect(b.y).toBeGreaterThan(s.y);
    }
    // The board says the rates are indicative and when they are from.
    await expect(page.getByText("الأسعار استرشادية", { exact: false }).first()).toBeVisible();
    await expect(page.getByText("Weather data © OpenWeather")).toBeVisible();
  });

  test("the main story's picture is loaded at once, the others when they come near", async ({ page }) => {
    await page.goto("/");
    const first = page.locator("main img").first();
    await expect(first).toHaveAttribute("fetchpriority", "high");
    await expect(page.locator("main img[loading='lazy']").first()).toBeAttached();
  });
});

test.describe("the chapters", () => {
  test("follow the order the admin set, and «آخر الأخبار» repeats nothing from the first screen", async ({ page }) => {
    await page.goto("/");
    const shown = await page.locator("main section[id^='bab-'] h2").allTextContents();
    expect(shown.filter((t) => chapterNames.includes(t))).toEqual(chapterNames);
    await expect(page.locator("section#bab-4").getByRole("link", { name: lead.title })).toHaveCount(0);
  });

  test("show three items each on phones and tablets, more from 1024 px", async ({ page }) => {
    await page.goto("/");
    const width = page.viewportSize()!.width;
    for (const name of chapterNames) {
      const items = chapter(page, name).locator("article");
      const visible = await items.evaluateAll((all) => all.filter((a) => a.getClientRects().length > 0).length);
      if (!wide(width)) expect(visible, name).toBeLessThanOrEqual(3);
      else expect(visible, name).toBeGreaterThanOrEqual(1);
    }
  });

  test("history hangs on the kashida's line, oldest first; nothing else uses the line", async ({ page }) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/");
    const history = blocks.find((b) => b.section?.key === "nablus_history")!;
    const line = chapter(page, history.section!.name).locator("ol");
    const years = (await line.locator("li p.figures").allTextContents()).map(Number);
    expect(years.length).toBeGreaterThan(0);
    expect(years).toEqual([...years].sort((a, b) => a - b));
    // With reduced motion the line is drawn complete and still.
    const border = await line.evaluate((el) => getComputedStyle(el).borderInlineStartColor);
    expect(border).not.toBe("rgba(0, 0, 0, 0)");
    expect(await line.evaluate((el) => el.getAnimations({ subtree: true }).length)).toBe(0);
    await expect(page.locator("main ol[class*='line']")).toHaveCount(1);
  });

  test("videos show their length; the ad carries its label; the newsletter has its form", async ({ page }) => {
    await page.goto("/");
    const videos = chapter(page, blocks.find((b) => b.type === "videos")!.title);
    await expect(videos.getByText("المدة").first()).toBeAttached();
    await expect(videos.locator("li").first()).toBeVisible();
    await expect(page.getByRole("complementary", { name: "إعلان" }).first()).toBeAttached();
    await expect(page.getByRole("heading", { level: 2, name: "النشرة البريدية" })).toBeAttached();
    await expect(page.getByLabel("البريد الإلكتروني")).toBeAttached();
  });

  test("«الأبواب» comes after the first screen on phones and tablets, and stands on the page's edge from 1280 px", async ({ page }) => {
    await page.goto("/");
    const width = page.viewportSize()!.width;
    const row = page.getByRole("navigation", { name: "الأبواب" });
    if (width >= 1280) {
      await expect(row).toHaveCount(1);
      await expect(row).toBeVisible();
      await expect(row.getByRole("link").first()).toBeVisible();
    } else {
      await expect(row).toBeVisible();
      const r = (await row.boundingBox())!;
      const s = (await page.getByRole("heading", { level: 2, name: lead.title }).boundingBox())!;
      expect(r.y).toBeGreaterThan(s.y);
    }
    // Each tab leads to its chapter.
    const first = row.getByRole("link").first();
    const href = await first.getAttribute("href");
    await first.click();
    await expect(page.locator(href!)).toBeInViewport();
  });

  for (const theme of ["light", "dark"] as const) {
    test(`every control is at least 44 × 44 px, and nothing scrolls sideways (${theme})`, async ({ page }) => {
      await useTheme(page, theme);
      await page.goto("/");
      expect(await smallTargets(page, "main")).toEqual([]);
      await expectNoSidewaysScroll(page);
    });
  }
});

test.describe("what search engines read", () => {
  test("title, description, canonical address, Open Graph and the share card", async ({ page }) => {
    await page.goto("/");
    await expect(page).toHaveTitle("رقم: تاريخ الاقتصاد ومستقبله");
    await expect(page.locator("meta[name='description']")).toHaveAttribute("content", /.{50,}/);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", "https://example.org");
    await expect(page.locator("meta[property='og:type']")).toHaveAttribute("content", "website");
    await expect(page.locator("meta[property='og:url']")).toHaveAttribute("content", "https://example.org");
    await expect(page.locator("meta[property='og:image']")).toHaveAttribute("content", "https://example.org/og/default.png");
    await expect(page.locator("meta[name='twitter:card']")).toHaveAttribute("content", "summary_large_image");
  });

  test("structured data: a news organisation and a web site with its search", async ({ page, request }) => {
    await page.goto("/");
    const json = JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!);
    const graph = json["@graph"] as Record<string, unknown>[];
    const org = graph.find((n) => n["@type"] === "NewsMediaOrganization")!;
    const site = graph.find((n) => n["@type"] === "WebSite")!;
    expect(json["@context"]).toBe("https://schema.org");
    expect(org).toMatchObject({ "@id": "https://example.org/#organization", url: "https://example.org/", logo: { "@type": "ImageObject" } });
    expect(site).toMatchObject({
      url: "https://example.org/",
      inLanguage: "ar",
      publisher: { "@id": "https://example.org/#organization" },
      potentialAction: { "@type": "SearchAction", target: { urlTemplate: "https://example.org/search?q={search_term_string}" }, "query-input": "required name=search_term_string" },
    });
    // The default logo and share picture exist.
    expect((await request.get("/brand/logo-512.png")).status()).toBe(200);
    expect((await request.get("/og/default.png")).status()).toBe(200);
  });
});
