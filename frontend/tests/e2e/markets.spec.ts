import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, fakeApi, smallTargets, useTheme } from "./helpers";

/*
 * The currency and weather pages against the fake API's captured rates and cities: the table, the converter (which
 * says the rate it used and reads Arabic digits), one currency's page with its chart and its last days, every city and
 * one city's page, their statuses from the first visit, their structured data, and Recharts kept to the currency page.
 */

type Row = { code: string; path: string; name: string; buy: string; sell: string; mid: string; is_pegged: boolean };
type Point = { date: string; mid: string };
const board: { data: Row[]; meta: { note: string; published_at: string } } = JSON.parse(readFileSync("tests/fake-api/fixtures/currencies.json", "utf8"));
const markets: {
  history: Record<string, { data: { points: Point[] } }>;
  cities: { data: { slug: string; name: string; path: string; is_default: boolean; latitude: number; longitude: number }[] };
  city: Record<string, { data: { now: { humidity: number; wind_kmh: number; temperature: number } | null; forecast: { date: string }[] } }>;
  stale: string;
} = JSON.parse(readFileSync("tests/fake-api/fixtures/markets.json", "utf8"));
const site = "https://example.org";
const usd = board.data.find((r) => r.code === "USD")!;
const money = (value: number) => new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
const nablus = markets.cities.data.find((c) => c.is_default)!;

async function graphOf(page: Page) {
  return JSON.parse((await page.locator("script[type='application/ld+json']").textContent())!)["@graph"] as Record<string, unknown>[];
}

test.describe("/currencies", () => {
  test("today's table: buy and sell of every currency, «آخر تحديث», the rates are indicative, its structured data", async ({ page }) => {
    const response = await page.goto("/currencies");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("أسعار العملات مقابل الشيكل اليوم");
    await expect(page).toHaveTitle(/^أسعار العملات اليوم مقابل الشيكل: الدولار و/);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/currencies`);
    const table = page.getByRole("table", { name: /أسعار العملات مقابل الشيكل/ });
    await expect(table.locator("tbody tr")).toHaveCount(board.data.length);
    const first = table.locator("tbody tr").first();
    await expect(first.getByRole("link")).toHaveAttribute("href", usd.path);
    await expect(first.locator("td").nth(0)).toHaveText(usd.buy);
    await expect(first.locator("td").nth(1)).toHaveText(usd.sell);
    await expect(page.getByText(/^آخر تحديث:/).first()).toBeVisible();
    await expect(page.getByText(board.meta.note).first()).toBeVisible();
    // A pegged currency says how its rate is made.
    if (board.data.some((r) => r.is_pegged)) await expect(page.getByText(/من سعر الدولار وسعر الربط الثابت به/)).toBeVisible();

    const graph = await graphOf(page);
    const collection = graph.find((n) => n["@type"] === "CollectionPage") as { mainEntity: { itemListElement: { item: Record<string, unknown> }[] } };
    expect(collection.mainEntity.itemListElement).toHaveLength(board.data.length);
    expect(collection.mainEntity.itemListElement[0].item).toMatchObject({
      "@type": "ExchangeRateSpecification",
      currency: "USD",
      currentExchangeRate: { "@type": "UnitPriceSpecification", price: Number(usd.mid), priceCurrency: "ILS" },
    });
    expect(graph.find((n) => n["@type"] === "BreadcrumbList")).toMatchObject({ itemListElement: [{ name: "الرئيسية" }, { name: "أسعار العملات", item: `${site}/currencies` }] });
    await expectNoSidewaysScroll(page);
  });
});

test.describe("the converter", () => {
  test("says which rate it used, reads Arabic digits, converts both ways and refuses what is not a number", async ({ page }) => {
    await page.goto("/currencies");
    const converter = page.getByRole("region", { name: "محوّل العملات" });
    const result = converter.locator("output");
    const amount = converter.getByLabel("المبلغ");

    // Drawn on the server: 100 dollars to the shekel at the buy rate.
    await expect(result).toContainText(money(100 * Number(usd.buy)));
    await expect(converter.getByText(new RegExp(`بحسب سعر الشراء: 1 ${usd.name} = ${usd.buy.replace(".", "\\.")} شيكل`))).toBeVisible();

    await amount.fill("٢٥٠");
    await expect(result).toContainText(money(250 * Number(usd.buy)));
    await amount.fill("١٫٥");
    await expect(result).toContainText(money(1.5 * Number(usd.buy)));
    await amount.fill("1,000");
    await expect(result).toContainText(money(1000 * Number(usd.buy)));

    await converter.getByText("من الشيكل إلى الدولار").click();
    await expect(converter.getByRole("radio", { name: "من الشيكل إلى الدولار" })).toBeChecked();
    await expect(result).toContainText(money(1000 / Number(usd.sell)));
    await expect(converter.getByText(/^بحسب سعر البيع:/)).toBeVisible();

    const euro = board.data.find((r) => r.code === "EUR")!;
    await converter.getByLabel("العملة").selectOption("EUR");
    await expect(result).toContainText(money(1000 / Number(euro.sell)));

    await amount.fill("مية");
    await expect(amount).toHaveAttribute("aria-invalid", "true");
    await expect(converter.getByText("اكتب المبلغ بالأرقام، مثل 100 أو 2.5.")).toBeVisible();
    await expect(result).toHaveText("اكتب مبلغاً لترى قيمته.");
  });
});

test.describe("/currencies/{code}", () => {
  const points = markets.history.USD.data.points;

  test("«سعر الدولار مقابل الشيكل اليوم»: buy, sell, the change, its last days newest first, its structured data", async ({ page }) => {
    const response = await page.goto("/currencies/usd");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("سعر الدولار مقابل الشيكل اليوم");
    await expect(page).toHaveTitle("سعر الدولار مقابل الشيكل اليوم | رقم");
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/currencies/usd`);
    const today = page.getByRole("region", { name: "سعر اليوم" });
    await expect(today.getByRole("definition").nth(0)).toHaveText(usd.buy);
    await expect(today.getByRole("definition").nth(1)).toHaveText(usd.sell);
    // The converter starts on this currency.
    await expect(page.getByRole("region", { name: "محوّل العملات" }).getByLabel("العملة")).toHaveValue("USD");

    const days = page.getByRole("table", { name: /في آخر الأيام/ }).locator("tbody tr");
    await expect(days).toHaveCount(Math.min(10, points.length));
    await expect(days.first().locator("td").first()).toHaveText(points.at(-1)!.mid);
    await expect(days.first().locator("th time")).toHaveAttribute("datetime", points.at(-1)!.date);
    const highest = [...points].sort((a, b) => Number(b.mid) - Number(a.mid))[0];
    await expect(page.locator("figcaption")).toContainText(`الأعلى ${highest.mid}`);

    const graph = await graphOf(page);
    expect(graph.find((n) => n["@type"] === "WebPage")).toMatchObject({ url: `${site}/currencies/usd`, mainEntity: { "@type": "ExchangeRateSpecification", currency: "USD" } });
    expect(graph.find((n) => n["@type"] === "BreadcrumbList")).toMatchObject({ itemListElement: [{ name: "الرئيسية" }, { name: "أسعار العملات" }, { name: usd.name, item: `${site}/currencies/usd` }] });
    await expectNoSidewaysScroll(page);
  });

  test("the chart is drawn when it comes into view, from 30 days of the middle rate", async ({ page }) => {
    await page.goto("/currencies/usd");
    await page.getByRole("heading", { name: "آخر 30 يوماً" }).scrollIntoViewIfNeeded();
    await expect(page.locator("figure .recharts-surface")).toBeVisible();
    await expect(page.locator("figure .recharts-area-curve")).toHaveCount(1);
  });

  test("another case moves to the lower-case address; an unknown code is a 404 from the first visit", async ({ request }) => {
    const moved = await request.get("/currencies/USD", { maxRedirects: 0 });
    expect(moved.status()).toBe(301);
    expect(new URL(moved.headers().location, "http://x").pathname).toBe("/currencies/usd");
    for (const path of ["/currencies/xyz", "/currencies/us", "/currencies/usd1", "/currencies/usd/more"]) {
      expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
    }
  });
});

test.describe("the check before the page", () => {
  const checks = async (request: APIRequestContext, path: string) => ((await (await request.get(`${fakeApi}/__requests?path=${encodeURIComponent(path)}`)).json()) as { count: number }).count;
  const letters = () => Array.from({ length: 3 }, () => String.fromCharCode(97 + Math.floor(Math.random() * 26))).join("");

  test("two visits to an unknown currency or city ask the platform once; a known one at most once", async ({ request }) => {
    let code = letters();
    while (board.data.some((r) => r.code.toLowerCase() === code)) code = letters();
    const city = `مدينة-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    for (let i = 0; i < 2; i++) {
      expect((await request.get(`/currencies/${code}`)).status()).toBe(404);
      expect((await request.get(`/weather/${encodeURIComponent(city)}`)).status()).toBe(404);
    }
    expect(await checks(request, `/api/v1/currencies/${code}/history`)).toBe(1);
    expect(await checks(request, `/api/v1/weather/${city}`)).toBe(1);

    const before = await checks(request, "/api/v1/currencies/gbp/history");
    for (let i = 0; i < 2; i++) expect((await request.get("/currencies/gbp")).status()).toBe(200);
    expect((await checks(request, "/api/v1/currencies/gbp/history")) - before).toBeLessThanOrEqual(1);
  });
});

test.describe("/weather", () => {
  test("every city now, each leading to its page; an old reading says when it was taken; the credit is visible", async ({ page }) => {
    const response = await page.goto("/weather");
    expect(response?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("طقس فلسطين اليوم");
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}/weather`);
    const cities = page.locator("main ul > li").filter({ has: page.getByRole("heading", { level: 3 }) });
    await expect(cities).toHaveCount(markets.cities.data.length);
    await expect(cities.first().getByRole("link")).toHaveAttribute("href", nablus.path);
    const stale = markets.cities.data.find((c) => c.slug === markets.stale)!;
    await expect(cities.filter({ hasText: `طقس ${stale.name}` }).getByText(/^آخر قراءة الساعة/)).toBeVisible();
    await expect(page.getByText("Weather data © OpenWeather").first()).toBeVisible();
    const graph = await graphOf(page);
    expect(graph.find((n) => n["@type"] === "CollectionPage")).toMatchObject({ mainEntity: { "@type": "ItemList", numberOfItems: markets.cities.data.length } });
    await expectNoSidewaysScroll(page);
  });

  test("a city: now, low and high, humidity, wind with its direction, five days, the credit, the place", async ({ page }) => {
    const response = await page.goto(encodeURI(nablus.path));
    expect(response?.status()).toBe(200);
    const data = markets.city[nablus.slug].data;
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(`طقس ${nablus.name} اليوم`);
    await expect(page).toHaveTitle(`طقس ${nablus.name} اليوم | رقم`);
    await expect(page.locator("link[rel='canonical']")).toHaveAttribute("href", `${site}${encodeURI(nablus.path)}`);
    const now = page.getByRole("region", { name: "الطقس الآن" });
    await expect(now.getByText("الصغرى والعظمى اليوم")).toBeVisible();
    await expect(now.getByText(`${data.now!.humidity}%`)).toBeVisible();
    await expect(now.getByText(`${Math.round(data.now!.wind_kmh)}`, { exact: true })).toBeVisible();
    await expect(now.getByText(/^(شمالية|جنوبية|شرقية|غربية)/)).toBeVisible();
    await expect(page.getByRole("heading", { name: "توقعات الأيام الخمسة" })).toBeVisible();
    await expect(page.locator("#forecast-title + ol > li")).toHaveCount(data.forecast.length);
    await expect(page.getByText("Weather data © OpenWeather")).toBeVisible();
    await expect(page.getByRole("region", { name: "مدن أخرى" }).getByRole("link")).toHaveCount(markets.cities.data.length);

    const graph = await graphOf(page);
    expect(graph.find((n) => n["@type"] === "Place")).toMatchObject({ name: nablus.name, geo: { latitude: nablus.latitude, longitude: nablus.longitude } });
    expect(graph.find((n) => n["@type"] === "BreadcrumbList")).toMatchObject({ itemListElement: [{ name: "الرئيسية" }, { name: "الطقس" }, { name: nablus.name }] });
    await expectNoSidewaysScroll(page);
  });

  test("an unknown city and the platform's other weather addresses are 404s", async ({ request }) => {
    for (const path of [`/weather/${encodeURIComponent("مدينة-غير-موجودة")}`, "/weather/widget", "/weather/cities"]) {
      expect((await request.get(path, { maxRedirects: 0 })).status(), path).toBe(404);
    }
  });
});

test.describe("Recharts stays on the currency page", () => {
  // Text that survives minification: the class of Recharts' drawing surface.
  const fingerprint = "recharts-surface";

  async function scriptsOf(page: Page, path: string, scroll = false) {
    const bodies: Promise<string>[] = [];
    const listen = (response: import("@playwright/test").Response) => {
      if (response.request().resourceType() === "script") bodies.push(response.text().catch(() => ""));
    };
    page.on("response", listen);
    await page.goto(path, { waitUntil: "networkidle" });
    // Down the whole page, so anything loaded on the way is seen too.
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 400) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 50));
      }
    });
    if (scroll) await expect(page.locator("figure .recharts-surface")).toBeVisible();
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(300);
    page.off("response", listen);

    return Promise.all(bodies);
  }

  test("no other page loads it, not even when scrolled; the currency page loads it for its chart", async ({ page }) => {
    for (const path of ["/", "/currencies", "/weather", encodeURI(nablus.path), encodeURI("/news/300/مشروع-لتخزين-الطاقة-نابلس-يدخل-مرحلة-التشغيل-التجريبي")]) {
      const scripts = await scriptsOf(page, path);
      expect(scripts.length, path).toBeGreaterThan(0);
      expect(scripts.filter((s) => s.includes(fingerprint)), path).toEqual([]);
    }
    const scripts = await scriptsOf(page, "/currencies/usd", true);
    expect(scripts.some((s) => s.includes(fingerprint))).toBe(true);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const path of ["/currencies", "/currencies/egp", "/weather", encodeURI(nablus.path)]) {
      await page.goto(path);
      expect(await smallTargets(page, "main"), path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
