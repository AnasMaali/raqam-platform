import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, fakeApi, smallTargets, useTheme } from "./helpers";

/*
 * The services, the contact page and the pages from the panel, against the fake API's captured services and pages
 * (capture-pages.mjs; the contact details are made up): prices and offers, the contact buttons and their count, the
 * form through the site's route, a page at the root, the platform's redirects, and the statuses from the first visit.
 */

type Price = { mode: string; amount: string | null; currency: string | null; text: string; offer: { was_text: string } | null };
type Service = { slug: string; path: string; name: string; price: Price; contact: { whatsapp: string | null; phone: string | null; email: string | null } };
const fixture: {
  services: { data: { page: { title: string }; groups: { name: string; services: Service[] }[] }; meta: { booking: { enabled: boolean } } };
  detail: Record<string, { data: Service & { faqs: { question: string; answer: string }[]; deliverables: string[]; examples: { title: string }[] } }>;
  pages: Record<string, { slug: string; title: string; system_key: string | null }>;
} = JSON.parse(readFileSync("tests/fake-api/fixtures/pages.json", "utf8"));
const site = "https://example.org";
const all = fixture.services.data.groups.flatMap((g) => g.services);
const withQuestions = Object.values(fixture.detail).find((d) => d.data.faqs.length > 0)!.data;
const noLinks = all.find((s) => !s.contact.whatsapp && !s.contact.phone && !s.contact.email)!;

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

async function standInTurnstile(page: Page) {
  await page.route("https://challenges.cloudflare.com/**", (route) =>
    route.fulfill({
      contentType: "text/javascript",
      // Like Turnstile: a token at once, and a new one after each reset (a token is good once).
      body: "let o; window.turnstile = { render: (el, opts) => { o = opts; setTimeout(() => o.callback('pass:' + o.action), 50); return 'w1'; }, reset: () => setTimeout(() => o.callback('pass:' + o.action), 50) };",
    }),
  );
}

test.describe("/services", () => {
  test("the services in their groups, each price as the platform writes it, offers with their end, «تواصل للحجز»", async ({ page }) => {
    const response = await page.goto("/services");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(fixture.services.data.page.title);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/services`);
    for (const group of fixture.services.data.groups) await expect(page.getByRole("heading", { level: 2, name: group.name })).toBeVisible();
    await expect(page.locator("main article")).toHaveCount(all.length);
    for (const service of all) {
      const card = page.locator("main article").filter({ has: page.getByRole("link", { name: service.name, exact: true }) });
      await expect(card.getByText(service.price.text, { exact: true })).toBeVisible();
      await expect(card.getByRole("link", { name: `تواصل لحجز ${service.name}` })).toHaveAttribute("href", `${service.path}#contact`);
      if (service.price.offer) {
        await expect(card.getByText("عرض خاص")).toBeVisible();
        await expect(card.locator("s")).toHaveText(service.price.offer.was_text);
        await expect(card.locator("time")).toBeVisible();
      }
    }
    // Booking online is off: no button for it.
    expect(fixture.services.meta.booking.enabled).toBe(false);
    await expect(page.getByRole("link", { name: "احجز أونلاين" })).toHaveCount(0);

    const list = (await graphOf(page)).find((n) => n["@type"] === "WebPage") as { mainEntity: { itemListElement: { item: Record<string, unknown> }[] } };
    const nodes = list.mainEntity.itemListElement.map((i) => i.item);
    expect(nodes).toHaveLength(all.length);
    for (const [i, service] of all.entries()) {
      expect(nodes[i]).toMatchObject({ "@type": "Service", name: service.name, url: `${site}${service.path}` });
      if (service.price.mode === "fixed") expect(nodes[i].offers).toMatchObject({ "@type": "Offer", price: service.price.amount, priceCurrency: service.price.currency });
      if (service.price.mode === "from") expect(nodes[i].offers).toMatchObject({ priceSpecification: { minPrice: service.price.amount } });
      if (service.price.mode === "on_request") expect(nodes[i].offers).toBeUndefined();
    }
    await expectNoSidewaysScroll(page);
  });
});

test.describe("/services/{slug}", () => {
  test("details, what it includes, its questions, published work, Service and Offer, the breadcrumb", async ({ page }) => {
    const response = await page.goto(withQuestions.path);
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(withQuestions.name);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${withQuestions.path}`);
    for (const item of withQuestions.deliverables) await expect(page.getByText(item, { exact: true })).toBeVisible();
    const faq = withQuestions.faqs[0];
    await expect(page.getByText(faq.answer)).toBeHidden();
    await page.getByText(faq.question).click();
    await expect(page.getByText(faq.answer)).toBeVisible();
    await expect(page.getByRole("region", { name: "أعمال منشورة" }).getByRole("listitem")).toHaveCount(withQuestions.examples.length);

    const graph = await graphOf(page);
    expect(graph.find((n) => n["@type"] === "Service")).toMatchObject({ name: withQuestions.name, url: `${site}${withQuestions.path}`, offers: { "@type": "Offer" } });
    expect(graph.find((n) => n["@type"] === "BreadcrumbList")).toMatchObject({ itemListElement: [{ name: "الرئيسية" }, { name: "الخدمات" }, { name: withQuestions.name }] });
    await expectNoSidewaysScroll(page);
  });

  test("the contact buttons carry a ready message and subject; a press is counted through the site's server", async ({ page, request }) => {
    await page.goto(withQuestions.path);
    const buttons = page.getByRole("list", { name: `تواصل لحجز ${withQuestions.name}` });
    await expect(buttons.getByRole("link", { name: /واتساب/ })).toHaveAttribute("href", withQuestions.contact.whatsapp!);
    expect(decodeURIComponent(withQuestions.contact.whatsapp!)).toContain(withQuestions.name);
    await expect(buttons.getByRole("link", { name: "اتصال" })).toHaveAttribute("href", withQuestions.contact.phone!);
    await expect(buttons.getByRole("link", { name: "بريد إلكتروني" })).toHaveAttribute("href", withQuestions.contact.email!);
    expect(decodeURIComponent(withQuestions.contact.email!)).toContain(`?subject=`);

    const counted = async () => ((await (await request.get(`${fakeApi}/__presses?key=${encodeURIComponent(`${withQuestions.slug}:email`)}`)).json()) as { count: number }).count;
    const before = await counted();
    // The press itself: the mail link goes nowhere in a test browser, the report goes out with it.
    await buttons.getByRole("link", { name: "بريد إلكتروني" }).evaluate((a: HTMLAnchorElement) => {
      a.addEventListener("click", (e) => e.preventDefault());
      a.click();
    });
    await expect.poll(counted).toBe(before + 1);
  });

  test("a service without contact settings offers the contact page", async ({ page }) => {
    await page.goto(noLinks.path);
    await expect(page.locator("#contact").getByRole("link", { name: "تواصل معنا" })).toHaveAttribute("href", "/contact");
  });

  test("an unknown service is a 404; an old address moves to the service", async ({ request }) => {
    expect((await request.get("/services/no-such-service", { maxRedirects: 0 })).status()).toBe(404);
    const moved = await request.get("/services/old-podcast", { maxRedirects: 0 });
    expect(moved.status()).toBe(301);
    expect(new URL(moved.headers().location, "http://x").pathname).toBe("/services/podcast-episode");
  });

  test("the press route takes only the site's own pages and a known channel", async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    expect((await request.post("/api/services/podcast-episode/contact", { data: { channel: "email" }, headers: { Origin: "https://elsewhere.example" } })).status()).toBe(403);
    expect((await request.post("/api/services/podcast-episode/contact", { data: { channel: "fax" }, headers: { Origin: origin } })).status()).toBe(204);
  });
});

test.describe("/contact", () => {
  test("the contact details from the site, and the form refuses empty fields on the spot", async ({ page }) => {
    await standInTurnstile(page);
    const response = await page.goto("/contact");
    expect(response?.status()).toBe(200);
    const details = page.getByRole("region", { name: "بيانات التواصل" });
    await expect(details.getByRole("link", { name: /\+1555/ })).toHaveAttribute("href", /^tel:\+/);
    await expect(details.getByRole("link", { name: /contact@example\.org/ })).toHaveAttribute("href", "mailto:contact@example.org");
    await expect(details.getByRole("link", { name: /الخريطة/ })).toHaveAttribute("href", /^https:\/\//);
    expect((await graphOf(page)).find((n) => n["@type"] === "ContactPage")).toBeTruthy();

    await page.getByRole("button", { name: "أرسل الرسالة" }).click();
    await expect(page.getByLabel("الاسم")).toBeFocused();
    await expect(page.getByLabel("الاسم")).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByText("اكتب رسالتك (2–5000 حرف).")).toBeVisible();
    await expectNoSidewaysScroll(page);
  });

  test("a message goes through the site's route with the Turnstile token and the visitor's address; the platform's errors land on their fields", async ({ page, request }) => {
    await standInTurnstile(page);
    await page.goto("/contact");
    await page.getByLabel("الاسم").fill("قارئ تجريبي");
    await page.getByLabel("البريد الإلكتروني").fill("reader@example.com");
    await page.getByLabel(/الهاتف/).fill("12");
    await page.getByLabel("الموضوع").fill("سؤال عن الخدمات");
    await page.getByLabel("الرسالة").fill("نص الرسالة التجريبية.");
    await page.getByRole("button", { name: "أرسل الرسالة" }).click();
    // The platform refuses the phone: its own message, on the field.
    await expect(page.getByText("اكتب الرقم بالصيغة الدولية، مثال: +970599123456")).toBeVisible();
    await expect(page.getByLabel(/الهاتف/)).toBeFocused();

    await page.getByLabel(/الهاتف/).fill("");
    await page.getByRole("button", { name: "أرسل الرسالة" }).click();
    await expect(page.getByText("وصلت رسالتك.", { exact: false })).toBeVisible();
    const last = ((await (await request.get(`${fakeApi}/__contact`)).json()) as { last: { body: Record<string, unknown>; ip: string } }).last;
    expect(last.body).toMatchObject({ name: "قارئ تجريبي", email: "reader@example.com", phone: null, turnstile_token: "pass:contact", website: "" });
    expect(last.ip).toBeTruthy();
  });

  test("the form's route takes only the site's own pages, JSON, and 16 KB at most", async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    expect((await request.post("/api/contact", { data: { name: "x" }, headers: { Origin: "https://elsewhere.example" } })).status()).toBe(403);
    expect((await request.post("/api/contact", { data: "name=x", headers: { Origin: origin, "Content-Type": "application/x-www-form-urlencoded" } })).status()).toBe(415);
    expect((await request.post("/api/contact", { data: Buffer.from(JSON.stringify({ message: "x".repeat(20_000) })), headers: { Origin: origin, "Content-Type": "application/json" } })).status()).toBe(413);
  });
});

test.describe("the pages from the panel", () => {
  test("«من نحن», the policies and the legal pages, each at the root with its title, canonical and breadcrumb", async ({ page }) => {
    for (const slug of ["about", "editorial-policy", "corrections-policy", "sponsored-policy", "privacy", "terms", "cookies", "copyright"]) {
      const response = await page.goto(`/${slug}`);
      expect(response?.status(), slug).toBe(200);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(fixture.pages[slug].title);
      await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/${slug}`);
    }
    await page.goto("/about");
    expect((await graphOf(page)).find((n) => n["@type"] === "AboutPage")).toMatchObject({ url: `${site}/about` });
    // The editor left the description empty: the page's own first sentences stand in.
    await expect(page.locator("meta[name='description']")).toHaveAttribute("content", /\S{10,}/);
    await expectNoSidewaysScroll(page);
  });

  test("a page the admin adds later has the same template", async ({ page }) => {
    const response = await page.goto(encodeURI("/شروط-المسابقة"));
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("شروط المسابقة");
  });

  test("an unknown page and a word the platform keeps are 404s; a renamed page, a move and a removed page answer as the platform says", async ({ request }) => {
    for (const path of [`/no-such-page-${Date.now()}`, "/login", "/preview", "/category"]) expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
    const renamed = await request.get(encodeURI("/شروط-قديمة"), { maxRedirects: 0 });
    expect(renamed.status()).toBe(301);
    expect(decodeURI(new URL(renamed.headers().location, "http://x").pathname)).toBe("/شروط-المسابقة");
    const campaign = await request.get(encodeURI("/حملة"), { maxRedirects: 0 });
    expect(campaign.status()).toBe(302);
    expect(campaign.headers().location).toBe("https://example.org/campaign");
    expect((await request.get(encodeURI("/صفحة-محذوفة"), { maxRedirects: 0 })).status()).toBe(404);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    await standInTurnstile(page);
    for (const path of ["/services", withQuestions.path, "/contact", "/about"]) {
      await page.goto(path);
      expect(await smallTargets(page, "main"), path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
