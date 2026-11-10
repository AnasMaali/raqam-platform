import { expect, test, type Page } from "@playwright/test";

import { expectNoSidewaysScroll, fakeApi, smallTargets, useTheme } from "./helpers";

/*
 * The newsletter's own page (placement newsletter_page) and the unsubscribe page: opening the link changes nothing,
 * the button does; a mail program's one-click POST (RFC 8058) to the same link works too. Against the fake API: any
 * subscriber with the signature "a" × 64 is a good link (each test its own subscriber), the code 123456 confirms.
 */

const signature = "a".repeat(64);
const someone = () => String(1000 + Math.floor(Math.random() * 1_000_000_000));
const good = (s: string) => `s=${s}&t=${signature}`;

async function standInTurnstile(page: Page) {
  await page.route("https://challenges.cloudflare.com/**", (route) =>
    route.fulfill({
      contentType: "text/javascript",
      body: "let o; window.turnstile = { render: (el, opts) => { o = opts; setTimeout(() => o.callback('pass:' + o.action), 50); return 'w1'; }, reset: () => setTimeout(() => o.callback('pass:' + o.action), 50) };",
    }),
  );
}

const unsubscribedCount = async (request: import("@playwright/test").APIRequestContext, s: string) => ((await (await request.get(`${fakeApi}/__unsubscribed?s=${s}`)).json()) as { count: number }).count;

test("/newsletter: the address, the code, the confirmation, from the newsletter page's own place; noindex", async ({ page }) => {
  await standInTurnstile(page);
  const response = await page.goto("/newsletter");
  expect(response?.status()).toBe(200);
  await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("النشرة البريدية");
  await page.getByLabel("البريد الإلكتروني").fill("reader@example.com");
  await page.getByRole("button", { name: "اشترك" }).click();
  // The fake platform refuses any other place than (newsletter_page, /newsletter): reaching the code proves it.
  await page.getByLabel("رمز التأكيد").fill("123456");
  await page.getByRole("button", { name: "تأكيد" }).click();
  await expect(page.getByText("اشتركت في النشرة.", { exact: false })).toBeVisible();
  await expectNoSidewaysScroll(page);
});

test.describe("/newsletter/unsubscribe", () => {
  test("opening the link changes nothing; the button unsubscribes; the link's signature never leaves in a Referer", async ({ page, request }) => {
    const s = someone();
    const response = await page.goto(`/newsletter/unsubscribe?${good(s)}`);
    expect(response?.status()).toBe(200);
    await expect(page.locator("meta[name='robots']")).toHaveAttribute("content", /noindex/);
    await expect(page.locator("meta[name='referrer']")).toHaveAttribute("content", "no-referrer");
    expect(response?.headers()["cache-control"]).toMatch(/no-store/);
    expect(await unsubscribedCount(request, s)).toBe(0);
    await page.getByRole("button", { name: "ألغِ اشتراكي" }).click();
    await expect(page.getByText("ألغينا اشتراكك.", { exact: false })).toBeFocused();
    expect(await unsubscribedCount(request, s)).toBe(1);
    await expectNoSidewaysScroll(page);
  });

  test("a link the platform did not make says so; an incomplete one has no button", async ({ page }) => {
    await page.goto(`/newsletter/unsubscribe?s=42&t=${"b".repeat(64)}`);
    await page.getByRole("button", { name: "ألغِ اشتراكي" }).click();
    await expect(page.getByText("رابط إلغاء الاشتراك غير صالح.")).toBeVisible();
    await page.goto("/newsletter/unsubscribe?s=42");
    await expect(page.getByText("هذا الرابط غير مكتمل.", { exact: false })).toBeVisible();
    await expect(page.getByRole("button", { name: "ألغِ اشتراكي" })).toHaveCount(0);
  });

  test("a mail program's one-click POST to the same link unsubscribes; anything else is refused", async ({ request }) => {
    const s = someone();
    const form = { headers: { "Content-Type": "application/x-www-form-urlencoded" } };
    const ok = await request.post(`/newsletter/unsubscribe?${good(s)}`, { ...form, data: "List-Unsubscribe=One-Click" });
    expect(ok.status()).toBe(200);
    expect(await unsubscribedCount(request, s)).toBe(1);
    expect((await request.post(`/newsletter/unsubscribe?${good(s)}`, { ...form, data: "x=1" })).status()).toBe(400);
    expect((await request.post(`/newsletter/unsubscribe?s=42&t=${"b".repeat(64)}`, { ...form, data: "List-Unsubscribe=One-Click" })).status()).toBe(422);
  });

  test("the button's route takes only the site's own pages", async ({ request }) => {
    expect((await request.post("/api/newsletter/unsubscribe", { data: { s: someone(), t: signature }, headers: { Origin: "https://elsewhere.example" } })).status()).toBe(403);
  });
});

for (const theme of ["light", "dark"] as const) {
  test(`every control is at least 44 × 44 px and nothing scrolls sideways (${theme})`, async ({ page }) => {
    await useTheme(page, theme);
    for (const path of ["/newsletter", `/newsletter/unsubscribe?${good(someone())}`]) {
      await page.goto(path);
      expect(await smallTargets(page, "main"), path).toEqual([]);
      await expectNoSidewaysScroll(page);
    }
  });
}
