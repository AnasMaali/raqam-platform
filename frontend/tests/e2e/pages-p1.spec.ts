import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, smallTargets, useTheme } from "./helpers";

/*
 * The team, the questions, «أعلن معنا» and the sitemap for visitors, against the fake API (capture-p1.mjs; the contact
 * details and a writer's links are made up): the admin's order, FAQPage and the answers in the page, the places with
 * their sizes and the packages, and a sitemap whose every link answers.
 */

type Member = { id: number; name: string; slug: string; job_title: string | null; photo: unknown; links: Record<string, string> };
type Place = { code: string; name: string; sizes: { desktop: { width: number; height: number } | null; mobile: { width: number; height: number } | null } };
const fixture: {
  team: { data: Member[] };
  faqs: { data: { id: number; question: string; answer: string }[] };
  advertise: { data: { placements: Place[]; packages: { name: string; path: string; price: { text: string } }[]; contact: { whatsapp: string; phone: string; email: string } } };
} = JSON.parse(readFileSync("tests/fake-api/fixtures/p1.json", "utf8"));
const site = "https://example.org";

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

test.describe("/team", () => {
  test("every writer on the page in the admin's order, with the job, the bio, their page and their links; a Person list", async ({ page }) => {
    const response = await page.goto("/team");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "فريق العمل" })).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", `${site}/team`);
    const names = page.locator("main li article h2");
    await expect(names).toHaveText(fixture.team.data.map((m) => m.name));
    for (const m of fixture.team.data) {
      await expect(page.getByRole("link", { name: `مواد ${m.name}`, exact: true })).toHaveAttribute("href", `/author/${m.slug}`);
      if (m.job_title) await expect(page.getByText(m.job_title, { exact: true })).toBeVisible();
    }
    // A writer's own links open elsewhere; a writer without a photo shows the first letter of the name.
    const withLinks = fixture.team.data.find((m) => Object.keys(m.links).length > 0)!;
    for (const url of Object.values(withLinks.links)) await expect(page.locator(`a[href="${url}"]`)).toHaveAttribute("target", "_blank");
    const noPhoto = fixture.team.data.find((m) => m.photo === null)!;
    await expect(page.locator(`article[aria-labelledby="member-${noPhoto.id}"] img`)).toHaveCount(0);

    const node = (await graphOf(page)).find((n) => n["@id"] === `${site}/team`)!;
    const list = node.mainEntity as { itemListElement: { position: number; item: { "@type": string; name: string; url: string; sameAs?: string[] } }[] };
    expect(list.itemListElement.map((e) => e.item.name)).toEqual(fixture.team.data.map((m) => m.name));
    expect(list.itemListElement.every((e) => e.item["@type"] === "Person")).toBe(true);
    expect(list.itemListElement[0].item.url).toBe(`${site}${encodeURI(`/author/${fixture.team.data[0].slug}`)}`);
    expect(list.itemListElement.find((e) => e.item.name === withLinks.name)!.item.sameAs).toEqual(Object.values(withLinks.links));
  });
});

test.describe("/faq", () => {
  test("the questions in order, each answer in the page and opened by a press or the keyboard; FAQPage with them all", async ({ page }) => {
    const response = await page.goto("/faq");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "الأسئلة الشائعة" })).toBeVisible();
    await expect(page.locator("main details h2")).toHaveText(fixture.faqs.data.map((f) => f.question));
    // The answers are in the page from the start (for search engines and screen readers), shown when opened.
    const [first, second] = fixture.faqs.data;
    const answer = page.getByText(first.answer, { exact: true });
    await expect(answer).toBeAttached();
    await expect(answer).toBeHidden();
    await page.locator(`#q-${first.id} summary`).click();
    await expect(answer).toBeVisible();
    await page.locator(`#q-${second.id} summary`).focus();
    await page.keyboard.press("Enter");
    await expect(page.getByText(second.answer, { exact: true })).toBeVisible();
    await expect(page.getByRole("link", { name: "اكتب لنا" })).toHaveAttribute("href", "/contact");

    const node = (await graphOf(page)).find((n) => n["@id"] === `${site}/faq`)!;
    expect(node["@type"]).toBe("FAQPage");
    expect(node.mainEntity).toEqual(fixture.faqs.data.map((f) => ({ "@type": "Question", name: f.question, acceptedAnswer: { "@type": "Answer", text: f.answer } })));
  });
});

test.describe("/advertise", () => {
  test("the places with their sizes, the packages with their prices, the contact buttons (not counted as a service's)", async ({ page }) => {
    const counted: string[] = [];
    page.on("request", (r) => {
      if (r.url().includes("/api/services/")) counted.push(r.url());
    });
    const response = await page.goto("/advertise");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "أعلن معنا" })).toBeVisible();
    const places = page.locator('section[aria-labelledby="ad-places"] li');
    await expect(places.locator("h3")).toHaveText(fixture.advertise.data.placements.map((p) => p.name));
    for (const [i, place] of fixture.advertise.data.placements.entries()) {
      for (const size of [place.sizes.desktop, place.sizes.mobile]) if (size) await expect(places.nth(i)).toContainText(`${size.width} × ${size.height}`);
    }
    for (const p of fixture.advertise.data.packages) {
      await expect(page.locator('section[aria-labelledby="ad-packages"]').getByRole("link", { name: p.name }).first()).toHaveAttribute("href", p.path);
      await expect(page.getByText(p.price.text)).toBeVisible();
    }
    const buttons = page.getByRole("list", { name: "تواصل لحجز إعلان في رَقَم" });
    await expect(buttons.getByRole("link", { name: /واتساب/ })).toHaveAttribute("href", fixture.advertise.data.contact.whatsapp);
    await expect(buttons.getByRole("link", { name: /اتصال/ })).toHaveAttribute("href", fixture.advertise.data.contact.phone);
    await page.route("https://wa.me/**", (route) => route.fulfill({ body: "" }));
    await buttons.getByRole("link", { name: /اتصال/ }).evaluate((a: HTMLAnchorElement) => a.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true })));
    await page.waitForTimeout(300);
    expect(counted).toEqual([]);

    const node = (await graphOf(page)).find((n) => n["@id"] === `${site}/advertise`)!;
    expect((node.mainEntity as { numberOfItems: number }).numberOfItems).toBe(fixture.advertise.data.packages.length);
  });
});

test.describe("/sitemap", () => {
  test("every part of the site in its group, and every link answers 200", async ({ page, request }) => {
    const response = await page.goto("/sitemap");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "خريطة الموقع" })).toBeVisible();
    for (const title of ["الأخبار والأقسام", "التصنيفات", "الأسعار والطقس", "الخدمات والإعلان", "عن رَقَم", "تابِع رَقَم"]) {
      await expect(page.getByRole("heading", { level: 2, name: title })).toBeVisible();
    }
    const hrefs = await page.locator("main section a").evaluateAll((all) => all.map((a) => a.getAttribute("href")!));
    for (const path of ["/news", "/podcast", "/currencies", "/currencies/usd", "/weather", "/services", "/advertise", "/team", "/faq", "/about", "/newsletter", "/feeds/posts"]) {
      expect(hrefs, path).toContain(path);
    }
    expect(new Set(hrefs).size).toBe(hrefs.length);
    for (const href of hrefs) expect((await request.get(href, { maxRedirects: 0 })).status(), decodeURI(href)).toBe(200);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`the four pages: no sideways scrolling and every control at least 44 × 44 px (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const path of ["/team", "/faq", "/advertise", "/sitemap"]) {
      await page.goto(path);
      await expectNoSidewaysScroll(page);
      expect(await smallTargets(page, "main"), path).toEqual([]);
    }
  });
}
