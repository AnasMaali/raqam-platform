import { readFileSync } from "node:fs";

import { expect, test, type Page } from "@playwright/test";

/*
 * The visitor's city (12.4, 12.5), against the fake API's weather (Nablus the default, Jenin's reading old): the position
 * is asked for only on a press of «أقرب مدينة لي» and never leaves the browser; the chosen city is kept in this browser
 * (localStorage, behind try/catch) and shown first on the home page, in a box that keeps its size; with storage blocked
 * everything still works.
 */

type City = { slug: string; name: string; path: string; latitude: number; longitude: number };
const cities: City[] = JSON.parse(readFileSync("tests/fake-api/fixtures/weather.json", "utf8")).data;
const city = (slug: string) => cities.find((c) => c.slug === slug)!;
const jenin = city("جنين");
const tulkarm = city("طولكرم");
const ramallah = city("رام-الله-والبيرة");
const key = "raqam:weather-city";

/** Counts every call that would ask the visitor for the position, or ask whether the site may. */
async function countPositionAsks(page: Page) {
  await page.addInitScript(() => {
    const w = window as unknown as { __asks: number };
    w.__asks = 0;
    const geo = navigator.geolocation as unknown as Record<string, (...args: unknown[]) => unknown>;
    for (const name of ["getCurrentPosition", "watchPosition"]) {
      const original = geo[name].bind(geo);
      geo[name] = (...args: unknown[]) => {
        w.__asks++;

        return original(...args);
      };
    }
    const query = navigator.permissions.query.bind(navigator.permissions);
    navigator.permissions.query = (descriptor: PermissionDescriptor) => {
      if (descriptor.name === "geolocation") w.__asks++;

      return query(descriptor);
    };
  });
}
const asks = (page: Page) => page.evaluate(() => (window as unknown as { __asks: number }).__asks);

const widget = (page: Page) => page.locator("section[aria-labelledby='weather-title']");

/** Makes every access to localStorage throw, as a browser that blocks the site's storage does. */
async function blockStorage(page: Page) {
  await page.addInitScript(() => {
    Object.defineProperty(window, "localStorage", {
      get() {
        throw new DOMException("The site's storage is blocked.", "SecurityError");
      },
    });
  });
}

test.describe("no page asks for the position on its own", () => {
  test("the home page, /weather and a city's page load without asking", async ({ page }) => {
    await countPositionAsks(page);
    for (const path of ["/", "/weather", encodeURI(tulkarm.path)]) {
      await page.goto(path);
      await page.waitForLoadState("networkidle");
      expect(await asks(page), path).toBe(0);
    }
  });
});

test.describe("«أقرب مدينة لي», with the position allowed", () => {
  test.use({ permissions: ["geolocation"], geolocation: { latitude: jenin.latitude + 0.01, longitude: jenin.longitude - 0.01 } });

  test("asks once, on the press: the nearest city's page opens and the home page shows it first", async ({ page }) => {
    await countPositionAsks(page);
    const sent: string[] = [];
    page.on("request", (request) => sent.push(`${request.url()} ${request.postData() ?? ""}`));
    await page.goto("/weather");
    await page.waitForLoadState("networkidle");
    expect(await asks(page)).toBe(0);
    await page.getByRole("button", { name: "أقرب مدينة لي" }).click();
    await expect(page).toHaveURL(new RegExp(`${encodeURI(jenin.path)}$`));
    expect(await asks(page)).toBe(1);
    expect(await page.evaluate((k) => localStorage.getItem(k), key)).toBe(jenin.slug);
    // The position never leaves the browser.
    expect(sent.filter((s) => s.includes(String(jenin.latitude + 0.01).slice(0, 6)))).toEqual([]);
    await expect(page.getByRole("status").filter({ hasText: `${jenin.name} مدينتك المحفوظة.` })).toBeVisible();

    await page.goto("/");
    await expect(widget(page).getByRole("heading", { name: `طقس ${jenin.name}` })).toBeVisible();
    // The default city takes the chosen one's place in the list beside it.
    await expect(widget(page).getByRole("link", { name: /نابلس/ })).toBeVisible();
    await expect(widget(page).getByRole("link", { name: new RegExp(jenin.name) })).toHaveCount(0);
  });
});

test.describe("«أقرب مدينة لي», with the position refused", () => {
  test("says so, keeps the page and remembers nothing", async ({ page }) => {
    await page.goto("/weather");
    await page.getByRole("button", { name: "أقرب مدينة لي" }).click();
    await expect(page.getByRole("status").filter({ hasText: "لم تسمح بمعرفة موقعك" })).toBeVisible();
    await expect(page).toHaveURL(/\/weather$/);
    expect(await page.evaluate((k) => localStorage.getItem(k), key)).toBeNull();
  });
});

test("«اجعلها مدينتي» on a city's page keeps it, says so after a reload, and the home page shows it first", async ({ page }) => {
  await page.goto(encodeURI(tulkarm.path));
  await page.getByRole("button", { name: "اجعلها مدينتي" }).click();
  await expect(page.getByRole("status").filter({ hasText: `حفظنا ${tulkarm.name} مدينتك في الرئيسية.` })).toBeVisible();
  await page.reload();
  await expect(page.getByRole("status").filter({ hasText: `${tulkarm.name} مدينتك المحفوظة.` })).toBeVisible();
  await expect(page.getByRole("button", { name: "اجعلها مدينتي" })).toHaveCount(0);
  await page.goto("/");
  await expect(widget(page).getByRole("heading", { name: `طقس ${tulkarm.name}` })).toBeVisible();
  // A city the platform does not list any more is ignored: the default comes back.
  await page.evaluate((k) => localStorage.setItem(k, "no-such-city"), key);
  await page.reload();
  await expect(widget(page).getByRole("heading", { name: "طقس نابلس" })).toBeVisible();
});

test("the home page's box keeps its size whatever the city, and the change moves nothing", async ({ page }) => {
  const box = async () => (await widget(page).boundingBox())!;
  await page.goto("/");
  await expect(widget(page).getByRole("heading", { name: "طقس نابلس" })).toBeVisible();
  const usual = await box();

  // The longest name, then an old reading (its line was kept empty for the others).
  for (const chosen of [ramallah, jenin]) {
    await page.evaluate(([k, slug]) => localStorage.setItem(k, slug), [key, chosen.slug]);
    await page.goto("/");
    await expect(widget(page).getByRole("heading", { name: `طقس ${chosen.name}` })).toBeVisible();
    const now = await box();
    expect([Math.round(now.width), Math.round(now.height)], chosen.name).toEqual([Math.round(usual.width), Math.round(usual.height)]);
    const shift = await page.evaluate(
      () =>
        new Promise<number>((resolve) => {
          let total = 0;
          new PerformanceObserver((list) => {
            for (const entry of list.getEntries() as (PerformanceEntry & { value: number })[]) total += entry.value;
          }).observe({ type: "layout-shift", buffered: true });
          setTimeout(() => resolve(total), 500);
        }),
    );
    // The page's whole layout shift, the change of city included, stays where it was without it (no shift at all).
    expect(shift, chosen.name).toBe(0);
  }
  await expect(widget(page).getByText(/^آخر قراءة الساعة/)).toBeVisible();
});

test.describe("with the site's storage blocked", () => {
  test.use({ permissions: ["geolocation"], geolocation: { latitude: jenin.latitude, longitude: jenin.longitude } });

  test("the pages work the same: the default city, a plain message on «اجعلها مدينتي», the nearest city still opens", async ({ page }) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await blockStorage(page);
    await page.goto("/");
    await expect(widget(page).getByRole("heading", { name: "طقس نابلس" })).toBeVisible();
    await page.goto(encodeURI(tulkarm.path));
    await page.getByRole("button", { name: "اجعلها مدينتي" }).click();
    await expect(page.getByRole("status").filter({ hasText: "لا يسمح هذا المتصفح بحفظ اختيارك." })).toBeVisible();
    await page.goto("/weather");
    await page.getByRole("button", { name: "أقرب مدينة لي" }).click();
    await expect(page).toHaveURL(new RegExp(`${encodeURI(jenin.path)}$`));
    expect(errors).toEqual([]);
  });
});
