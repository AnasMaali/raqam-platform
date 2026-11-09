import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, fakeApi, smallTargets, useTheme } from "./helpers";

/*
 * The article template, shared by every kind, against the fake API's posts (one of each kind, and a made-up variant
 * that is sponsored, corrected, without an author or a picture, with a long title).
 */

type Summary = { id: number; title: string; path: string; slug: string; kind: { code: string; schema_type: string } };
const posts: Summary[] = JSON.parse(readFileSync("tests/fake-api/fixtures/posts.json", "utf8")).data;
const detail = (id: number) => JSON.parse(readFileSync(`tests/fake-api/fixtures/posts/${id}.json`, "utf8")).data;
const byKind = (code: string) => posts.find((p) => p.kind.code === code && p.id !== 900001)!;
const variant = posts.find((p) => p.id === 900001)!;
const site = "https://example.org";

async function graphOf(page: Page) {
  const json = JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!);

  return json["@graph"] as Record<string, unknown>[];
}

test.describe("every kind", () => {
  for (const post of posts) {
    test(`${post.kind.code} (${post.id}): its headline, breadcrumb, canonical address and structured data`, async ({ page }) => {
      const response = await page.goto(encodeURI(post.path));
      expect(response?.status()).toBe(200);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(post.title);
      await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${encodeURI(post.path)}`);
      await expect(page.locator("meta[property='og:type']")).toHaveAttribute("content", "article");
      await expect(page.locator("meta[property='article:published_time']")).toHaveAttribute("content", detail(post.id).published_at);

      const graph = await graphOf(page);
      const article = graph.find((n) => n["@id"] === `${site}${encodeURI(post.path)}#article`)!;
      expect(article["@type"]).toBe(post.kind.schema_type);
      expect(article).toMatchObject({ headline: expect.any(String), datePublished: expect.any(String), dateModified: expect.any(String), inLanguage: "ar", publisher: { "@id": `${site}/#organization` } });
      const crumbs = graph.find((n) => n["@type"] === "BreadcrumbList") as { itemListElement: { position: number; name: string; item: string }[] };
      expect(crumbs.itemListElement[0]).toMatchObject({ position: 1, name: "الرئيسية", item: `${site}/` });
      expect(crumbs.itemListElement.at(-1)).toMatchObject({ name: post.title, item: `${site}${encodeURI(post.path)}` });
      await expect(page.getByRole("navigation", { name: "مسار الصفحة" }).getByRole("link", { name: "الرئيسية" })).toBeVisible();
      await expectNoSidewaysScroll(page);
    });
  }
});

test.describe("addresses", () => {
  const news = byKind("news");

  test("an old slug and an address without a slug answer 301 to the one address, on the first visit", async ({ request }) => {
    // Fresh addresses each run, so no cached answer helps: the status comes from the check before the page.
    const run = Date.now();
    for (const wrong of [`/news/${news.id}/old-slug-${run}`, `/news/${news.id}`]) {
      const response = await request.get(encodeURI(wrong), { maxRedirects: 0 });
      expect(response.status(), wrong).toBe(301);
      expect(decodeURI(new URL(response.headers().location, "http://x").pathname), wrong).toBe(news.path);
    }
  });

  test("the right slug under another section's prefix answers 301 to the one address", async ({ request }) => {
    const response = await request.get(encodeURI(`/podcast/${news.id}/${news.slug}`), { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    expect(decodeURI(new URL(response.headers().location, "http://x").pathname)).toBe(news.path);
  });

  test("an unknown, removed or malformed post answers 404 inside the frame", async ({ page, request }) => {
    const run = Date.now();
    for (const path of [`/news/${(run % 900_000_000) + 100_000}/x`, "/news/900410/x", `/news/abc${run}/x`, "/news/0/x", "/news/300/x/y"]) {
      expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
    }
    await page.goto("/news/12345/x");
    await expect(page.getByRole("heading", { level: 1, name: "الصفحة غير موجودة" })).toBeVisible();
    // Under no known section, the shared route itself says not found, and keeps it out of search engines.
    await page.goto(`/unknown-section/300/x${run}`);
    await expect(page.getByRole("heading", { level: 1, name: "الصفحة غير موجودة" })).toBeVisible();
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
  });
});

test.describe("the check before the page", () => {
  // How many times the site asked the platform (HEAD) about one post address.
  const checks = async (request: APIRequestContext, id: number, slug: string) =>
    ((await (await request.get(`${fakeApi}/__requests?path=${encodeURIComponent(`/api/v1/posts/${id}/${slug}`)}`)).json()) as { count: number }).count;

  test("two visits to the same address ask the platform once: a page, a move and a missing post", async ({ request }) => {
    const run = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const cases = [
      { id: 900200, slug: `صفحة-${run}`, status: 200 },
      { id: byKind("news").id, slug: `قديم-${run}`, status: 301 },
      { id: (Date.now() % 900_000_000) + 100_000, slug: `x-${run}`, status: 404 },
    ];
    for (const c of cases) {
      for (let visit = 0; visit < 2; visit++) {
        const response = await request.get(encodeURI(`/news/${c.id}/${c.slug}`), { maxRedirects: 0 });
        expect(response.status(), `${c.slug} visit ${visit + 1}`).toBe(c.status);
      }
      expect(await checks(request, c.id, c.slug), c.slug).toBe(1);
    }
  });

  test("an address of another shape is a 404 from the first visit; the site's files are left alone", async ({ request }) => {
    const run = Date.now();
    for (const path of [`/x${run}/1/a`, `/x${run}/123`, `/x${run}/a/b`, `/awareness/characters/x${run}`, `/news/x${run}/a`]) {
      expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
    }
    for (const path of ["/og/default.png", "/brand/logo-512.png"]) expect((await request.get(path)).status(), path).toBe(200);
  });

  test("a platform error is not kept: the next visit asks again", async ({ request }) => {
    const slug = `x-${Date.now()}`;
    for (let visit = 0; visit < 2; visit++) await request.get(`/news/900503/${slug}`, { maxRedirects: 0 });
    expect(await checks(request, 900503, slug)).toBe(2);
  });
});

test.describe("the parts every article has", () => {
  test("writers link to their pages; dates are absolute, with the year", async ({ page }) => {
    const news = byKind("news");
    await page.goto(encodeURI(news.path));
    for (const author of detail(news.id).authors) {
      await expect(page.getByRole("link", { name: author.name })).toHaveAttribute("href", `/author/${author.slug}`);
    }
    await expect(page.locator("header time").first()).toHaveText(/\d{4}/);
  });

  test("without a writer: «فريق رَقَم»; sponsored: labelled first, its links marked as paid; a correction is stated", async ({ page }) => {
    await page.goto(encodeURI(variant.path));
    await expect(page.getByText("فريق رَقَم")).toBeVisible();
    const sponsor = detail(variant.id).sponsor;
    await expect(page.getByText("محتوى ممول")).toBeVisible();
    await expect(page.getByRole("link", { name: sponsor.name })).toHaveAttribute("rel", "sponsored noopener");
    await expect(page.getByRole("link", { name: "رابط للراعي" })).toHaveAttribute("rel", "noopener sponsored");
    await expect(page.getByRole("link", { name: "داخلي" })).not.toHaveAttribute("rel", /sponsored/);
    await expect(page.getByRole("heading", { name: "تصحيح" })).toBeVisible();
    // The editor's choice to keep it out of search engines.
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
    const article = (await graphOf(page)).find((n) => String(n["@id"]).endsWith("#article"))!;
    expect(article.author).toEqual([{ "@type": "Organization", name: "فريق رَقَم", url: `${site}/` }]);
  });

  test("the ad inside the article sits between paragraphs, after the third", async ({ page }) => {
    await page.goto(encodeURI(variant.path));
    const order = await page.locator("main > article").evaluate((el) => {
      const ad = el.querySelector("aside[aria-label='إعلان']");
      const paragraphs = [...el.querySelectorAll("p")].filter((p) => p.textContent?.startsWith("فقرة"));
      return paragraphs.map((p) => (ad!.compareDocumentPosition(p) & Node.DOCUMENT_POSITION_FOLLOWING ? "after" : "before"));
    });
    expect(order).toEqual(["before", "before", "before", "after"]);
  });

  test("tags link to their pages; «المزيد من …» lists other items of the same category", async ({ page }) => {
    const news = byKind("news");
    await page.goto(encodeURI(news.path));
    for (const tag of detail(news.id).tags) await expect(page.getByRole("link", { name: tag.name })).toHaveAttribute("href", `/tag/${tag.slug}`);
    const more = page.getByRole("heading", { name: /^المزيد من/ });
    if (await more.count()) await expect(page.getByRole("link", { name: /^كل المواد في/ })).toBeVisible();
  });

  test("the newsletter box closes the article, as a form of its own (post_end)", async ({ page }) => {
    await page.route("https://challenges.cloudflare.com/**", (route) =>
      route.fulfill({ contentType: "text/javascript", body: "window.turnstile = { render: (el, o) => { setTimeout(() => o.callback('pass:' + o.action), 50); return 'w1'; }, reset: () => {} };" }),
    );
    let sent: Record<string, unknown> | null = null;
    await page.route("**/api/newsletter/subscribe", async (route) => {
      sent = route.request().postDataJSON();
      await route.fulfill({ status: 202, contentType: "application/json", body: JSON.stringify({ data: { resend_after: 60, expires_in: 1800 } }) });
    });
    const news = byKind("news");
    await page.goto(encodeURI(news.path));
    await page.getByLabel("البريد الإلكتروني").fill("reader@example.com");
    await page.getByRole("button", { name: "اشترك" }).click();
    await expect(page.getByLabel("رمز التأكيد")).toBeVisible();
    expect(sent).toMatchObject({ placement: "post_end", path: news.path });
  });
});

test.describe("each kind's own parts", () => {
  test("an episode: season and number, a light player that loads YouTube only on press, chapters that start there", async ({ page }) => {
    const asked: string[] = [];
    await page.route("https://www.youtube-nocookie.com/**", (route) => {
      asked.push(route.request().url());
      return route.fulfill({ contentType: "text/html", body: "<html></html>" });
    });
    const episode = byKind("episode");
    const data = detail(episode.id);
    await page.goto(encodeURI(episode.path));
    await expect(page.getByText(`الموسم ${data.details.season.number} · الحلقة ${data.details.episode_number}`)).toBeVisible();
    await page.waitForLoadState("networkidle");
    expect(asked).toEqual([]);
    const chapter = data.video.chapters[1];
    await page.getByRole("navigation", { name: "فصول الفيديو" }).getByRole("button", { name: new RegExp(chapter.title) }).click();
    await expect(page.locator("iframe[src*='youtube-nocookie.com']")).toHaveAttribute("src", new RegExp(`start=${chapter.start}`));
    await expect(page.getByRole("heading", { name: "المقدّم والضيوف" })).toBeVisible();
    const video = (await graphOf(page)).find((n) => n["@type"] === "VideoObject");
    expect(video).toMatchObject({ name: data.video.title });
  });

  test("an infographic: shown large, opened at full size, downloaded", async ({ page }) => {
    const infographic = byKind("infographic");
    await page.goto(encodeURI(infographic.path));
    const image = detail(infographic.id).details.download;
    await expect(page.getByRole("link", { name: "افتح بالحجم الكامل" })).toHaveAttribute("href", image.url);
    await expect(page.getByRole("link", { name: "نزّل الصورة" })).toHaveAttribute("download", "");
    await expect(page.locator(`img[src='${image.url}']`)).toHaveAttribute("fetchpriority", "high");
  });

  test("a success story: its figures and its company; a product: its maker and the large gallery", async ({ page }) => {
    const story = detail(byKind("story").id);
    await page.goto(encodeURI(story.path));
    for (const f of story.details.figures) await expect(page.getByText(f.label, { exact: true }).first()).toBeVisible();
    await expect(page.getByRole("complementary", { name: "بطاقة المادة" }).getByText(story.details.organization.name)).toBeVisible();

    const product = detail(byKind("product").id);
    await page.goto(encodeURI(product.path));
    await expect(page.getByRole("complementary", { name: "بطاقة المادة" }).getByText(product.details.organization.name)).toBeVisible();
    await expect(page.getByRole("heading", { name: "الصور" })).toBeVisible();
  });

  test("history, an initiative, a round table and an awareness card show their facts", async ({ page }) => {
    const history = detail(byKind("history").id);
    await page.goto(encodeURI(history.path));
    // In the item's own facts box (the side column may list other items of the same era).
    const facts = page.getByRole("complementary", { name: "بطاقة المادة" });
    await expect(facts.getByText(history.details.era.name, { exact: true })).toBeVisible();

    const initiative = detail(byKind("initiative").id);
    await page.goto(encodeURI(initiative.path));
    await expect(facts.getByText(initiative.details.status === "ongoing" ? "جارية" : "منتهية", { exact: true })).toBeVisible();

    const table = detail(byKind("roundtable").id);
    await page.goto(encodeURI(table.path));
    await expect(page.getByRole("heading", { name: "المشاركون" })).toBeVisible();

    const card = detail(byKind("awareness").id);
    await page.goto(encodeURI(card.path));
    await expect(page.getByText(card.details.character.name).first()).toBeVisible();
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const post of [byKind("news"), byKind("episode"), byKind("story"), variant]) {
      await page.goto(encodeURI(post.path));
      expect(await smallTargets(page, "main"), post.path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
