import { readFileSync } from "node:fs";

import { expect, test } from "@playwright/test";

import { cleanQuery, marked } from "../../src/lib/search";

import { expectNoSidewaysScroll, fakeApi, smallTargets, useTheme } from "./helpers";

/*
 * The search against the fake API's captured answers (capture-search.mjs): the results page without JavaScript, the
 * instant results while typing (one request after the typing stops, never counted), the filters, the pages, nothing
 * found, the keyboard, and the site's route: its own pages only, never cached, 60 a minute per visitor.
 */

type Answer = { data: { posts: { title: string; path: string }[]; latest?: unknown[]; most_read?: unknown[]; suggestions?: string[] }; meta: { total: number; last_page: number } };
const fixture: { answers: Record<string, Answer>; empty: Answer } = JSON.parse(readFileSync("tests/fake-api/fixtures/search.json", "utf8"));
const energy = fixture.answers[new URLSearchParams({ q: "الطاقة" }).toString()];
const energyNews = fixture.answers[new URLSearchParams({ q: "الطاقة", kind: "news" }).toString()];

const tracked = async (request: import("@playwright/test").APIRequestContext) => ((await (await request.get(`${fakeApi}/__searches`)).json()) as { tracked: string[] }).tracked;

test.describe("without JavaScript", () => {
  test.use({ javaScriptEnabled: false });

  test("the form is a plain GET form: the results page is drawn by the server, noindex, and the search is counted", async ({ page, request }) => {
    await page.goto("/search");
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).fill("الطاقة");
    await page.getByRole("button", { name: "ابحث" }).click();
    await expect(page).toHaveURL(/\/search\?q=/);
    await expect(page.locator("main ol > li article")).toHaveCount(energy.data.posts.length);
    await expect(page.getByRole("link", { name: energy.data.posts[0].title })).toHaveAttribute("href", energy.data.posts[0].path);
    await expect(page.locator("main article h2 mark").first()).toHaveText("الطاقة");
    await expect(page.getByText(`${energy.meta.total} نتيجة لـ«الطاقة»`, { exact: false })).toBeVisible();
    // A query of its own, so the other tests' searches do not count here: the platform was told once.
    const own = `بحث${Date.now()}${Math.random().toString(36).slice(2, 6)}`;
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).fill(own);
    await page.getByRole("button", { name: "ابحث" }).click();
    await expect(page.getByText(`لا نتائج لـ«${own}»`)).toBeVisible();
    expect((await tracked(request)).filter((q) => q === own)).toHaveLength(1);
  });

  test("the page and the query are never kept in a shared cache", async ({ request }) => {
    const response = await request.get(`/search?q=${encodeURIComponent("الطاقة")}`);
    expect(response.headers()["cache-control"]).toMatch(/no-store/);
    expect(response.headers()["cache-control"]).toMatch(/private/);
  });
});

test.describe("instant results", () => {
  test("follow the typing, 250 ms after the last key, with one request and no count; the address follows", async ({ page, request }) => {
    await page.goto("/search");
    const asked: string[] = [];
    page.on("request", (r) => {
      if (r.url().includes("/api/search")) asked.push(r.url());
    });
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).pressSequentially("الطاقة", { delay: 40 });
    await expect(page.locator("main ol > li article")).toHaveCount(energy.data.posts.length);
    await expect(page).toHaveURL(/\/search\?q=/);
    expect(asked, asked.join("\n")).toHaveLength(1);
    await expect(page.locator("#search-count")).toContainText(`${energy.meta.total} نتيجة`);
    // Typing is not a search the visitor settled on: a query of its own, never counted.
    const own = `كتابة${Date.now()}${Math.random().toString(36).slice(2, 6)}`;
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).fill(own);
    await expect(page.locator("#search-count")).toContainText(own);
    expect(await tracked(request)).not.toContain(own);
  });

  test("a filter changes the results at once; Enter opens the results page with the filters", async ({ page }) => {
    await page.goto(`/search?q=${encodeURIComponent("الطاقة")}`);
    await page.getByText("التصفية والترتيب").click();
    await page.getByLabel("النوع").selectOption("news");
    await expect(page.locator("#search-count")).toContainText(`${energyNews.meta.total} نتيجة`);
    await expect(page).toHaveURL(/kind=news/);
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).press("Enter");
    await page.waitForURL(/kind=news/);
    await expect(page.locator("main ol > li article")).toHaveCount(energyNews.data.posts.length);
    await expect(page.getByLabel("النوع")).toHaveValue("news");
  });

  test("an older request still on its way is cancelled when the visitor types on", async ({ page }) => {
    await page.goto("/search");
    let answered = 0;
    // The first answer is slow: it must never land after the second.
    await page.route("**/api/search?**", async (route) => {
      const slow = answered++ === 0;
      if (slow) await new Promise((r) => setTimeout(r, 1500));
      await route.continue().catch(() => undefined);
    });
    const box = page.getByRole("searchbox", { name: "ابحث في رَقَم" });
    await box.fill("الطاقة");
    await page.waitForTimeout(400);
    await box.fill("محمد");
    await expect(page.locator("#search-count")).toContainText("لـ«محمد»");
    await page.waitForTimeout(1600);
    await expect(page.locator("#search-count")).toContainText("لـ«محمد»");
  });
});

test.describe("results", () => {
  test("pages of 12, each its own address; writers and tags that match", async ({ page }) => {
    await page.goto(`/search?q=${encodeURIComponent("الطاقة")}`);
    const pages = page.getByRole("navigation", { name: "صفحات النتائج" });
    await expect(pages.getByRole("link", { name: "التالي" })).toBeVisible();
    await pages.getByRole("link", { name: "صفحة 2" }).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(pages.locator("[aria-current='page']")).toHaveText("2");
    await page.goto(`/search?q=${encodeURIComponent("محمد")}`);
    await expect(page.getByRole("region", { name: "كتّاب وضيوف ووسوم" }).getByRole("link").first()).toBeVisible();
  });

  test("nothing found: close words, the latest items and the most read", async ({ page }) => {
    await page.goto(`/search?q=${encodeURIComponent(`لاشيء${Date.now()}`)}`);
    await expect(page.locator("#search-count")).toContainText("لا نتائج");
    for (const word of fixture.empty.data.suggestions!) await expect(page.getByRole("link", { name: word, exact: true })).toHaveAttribute("href", `/search?q=${encodeURIComponent(word).replace(/%20/g, "+")}`);
    await expect(page.getByRole("heading", { name: "أحدث المواد" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "الأكثر قراءة" })).toBeVisible();
    await expectNoSidewaysScroll(page);
  });

  test("a title with «<», «&» and quotes reads as written, with only the matched word marked", async ({ page }) => {
    await page.goto(`/search?q=${encodeURIComponent("سعر")}`);
    const title = page.locator("main h2 a").filter({ hasText: "النمو" });
    await expect(title).toHaveText("النمو < 2% & سعر 'الدولار' <b>");
    await expect(title.locator("mark")).toHaveText("سعر");
    await expect(title.locator("b")).toHaveCount(0);
  });

  test("the keyboard reaches the field, the button and the filters, in order", async ({ page }) => {
    await page.goto("/search");
    await page.getByRole("searchbox", { name: "ابحث في رَقَم" }).focus();
    await page.keyboard.press("Tab");
    await expect(page.getByRole("button", { name: "ابحث" })).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByText("التصفية والترتيب")).toBeFocused();
    await page.keyboard.press("Enter");
    await page.keyboard.press("Tab");
    await expect(page.getByLabel("التصنيف")).toBeFocused();
  });
});

test("the platform's highlighting keeps <mark> and nothing else; the query keeps only what the platform accepts", () => {
  // Everything is escaped and only the bare <mark> and </mark> come back: any other tag shows as text and never runs.
  expect(marked('<b>عنوان</b> <mark>الطاقة</mark><img src=x onerror="alert(1)"><script>x</script><mark class="x">')).toBe(
    "&lt;b&gt;عنوان&lt;/b&gt; <mark>الطاقة</mark>&lt;img src=x onerror=&quot;alert(1)&quot;&gt;&lt;script&gt;x&lt;/script&gt;&lt;mark class=&quot;x&quot;&gt;",
  );
  // A title with «<» and «&», escaped by the platform, comes out escaped once (not twice), so it reads as written; a
  // «<mark>» written in the title stays text.
  expect(marked("النمو &lt; 2% &amp; <mark>سعر</mark> &#039;الدولار&#039; &lt;mark&gt;")).toBe("النمو &lt; 2% &amp; <mark>سعر</mark> &#39;الدولار&#39; &lt;mark&gt;");
  expect(cleanQuery({ q: "  الطاقة   الشمسية ", kind: "nope", from: "2026-11-10", to: "2026-11-01", sort: "random", page: "99", category: "طاقة", evil: "1" })).toEqual({
    q: "الطاقة الشمسية",
    from: "2026-11-10",
    category: "طاقة",
  });
});

test.describe("the site's search route", () => {
  test("only from the site's own pages; never cached or indexed; 60 a minute per visitor", async ({ request }) => {
    const path = `/api/search?q=${encodeURIComponent("الطاقة")}`;
    expect((await request.get(path, { headers: { "Sec-Fetch-Site": "cross-site" } })).status()).toBe(403);
    const visitor = `10.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}`;
    const ok = await request.get(path, { headers: { "Sec-Fetch-Site": "same-origin", "X-Forwarded-For": visitor } });
    expect(ok.status()).toBe(200);
    expect(ok.headers()["cache-control"]).toMatch(/no-store/);
    expect(ok.headers()["x-robots-tag"]).toMatch(/noindex/);
    let last = 200;
    for (let i = 0; i < 60; i++) last = (await request.get(path, { headers: { "Sec-Fetch-Site": "same-origin", "X-Forwarded-For": visitor } })).status();
    expect(last).toBe(429);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    await page.goto(`/search?q=${encodeURIComponent("الطاقة")}&kind=news`);
    expect(await smallTargets(page, "main")).toEqual([]);
    await expectNoSidewaysScroll(page);
  });
}
