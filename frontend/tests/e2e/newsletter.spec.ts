import { expect, test, type Page } from "@playwright/test";

/*
 * The home page's newsletter form, against the fake API (the code 123456 confirms). Turnstile is replaced by a stand-in
 * that answers at once, so no request leaves for Cloudflare.
 */

async function standInTurnstile(page: Page) {
  await page.route("https://challenges.cloudflare.com/**", (route) =>
    route.fulfill({
      contentType: "text/javascript",
      body: "window.turnstile = { render: (el, o) => { setTimeout(() => o.callback('pass:' + o.action), 50); return 'w1'; }, reset: () => {} };",
    }),
  );
}

async function subscribe(page: Page, email = "reader@example.com") {
  await standInTurnstile(page);
  await page.goto("/");
  const field = page.getByLabel("البريد الإلكتروني");
  await field.scrollIntoViewIfNeeded();
  await field.fill(email);
  await page.getByRole("button", { name: "اشترك" }).click();
}

test.describe("the newsletter form", () => {
  test("address, then the code, then subscribed; the challenge never reaches the page's script", async ({ page, context }) => {
    await subscribe(page);
    const code = page.getByLabel("رمز التأكيد");
    await expect(code).toBeFocused();
    await expect(page.getByText("reader@example.com")).toBeVisible();

    const cookie = (await context.cookies()).find((c) => c.name === "raqam_newsletter");
    expect(cookie).toMatchObject({ httpOnly: true, sameSite: "Strict", path: "/api/newsletter" });
    expect(await page.evaluate(() => document.cookie)).not.toContain("raqam_newsletter");

    await code.fill("000000");
    await page.getByRole("button", { name: "تأكيد" }).click();
    await expect(page.getByText("الرمز غير صحيح.")).toBeVisible();
    await expect(code).toHaveAttribute("aria-invalid", "true");

    // Digits typed on an Arabic keyboard count.
    await code.fill("١٢٣٤٥٦");
    await page.getByRole("button", { name: "تأكيد" }).click();
    await expect(page.getByText("اشتركت في النشرة.", { exact: false })).toBeFocused();
    expect((await context.cookies()).find((c) => c.name === "raqam_newsletter")).toBeUndefined();
  });

  test("a new code waits its turn; the address can be changed", async ({ page }) => {
    await subscribe(page);
    const resend = page.getByRole("button", { name: /رمز جديد بعد/ });
    await expect(resend).toHaveAttribute("aria-disabled", "true");
    await page.getByRole("button", { name: "غيّر البريد" }).click();
    await expect(page.getByLabel("البريد الإلكتروني")).toBeFocused();
  });

  test("a wrong address shows the platform's message on the field", async ({ page }) => {
    await subscribe(page, "not-an-address@x");
    const field = page.getByLabel("البريد الإلكتروني");
    await expect(page.getByText("اكتب بريداً إلكترونياً صحيحاً.")).toBeVisible();
    await expect(field).toHaveAttribute("aria-invalid", "true");
  });

  test("a second press while sending sends once", async ({ page }) => {
    let sent = 0;
    await page.route("**/api/newsletter/subscribe", async (route) => {
      sent++;
      await new Promise((r) => setTimeout(r, 400));
      await route.continue();
    });
    await standInTurnstile(page);
    await page.goto("/");
    await page.getByLabel("البريد الإلكتروني").fill("reader@example.com");
    const button = page.getByRole("button", { name: "اشترك" });
    await button.click();
    await page.getByRole("button", { name: "جارٍ الإرسال…" }).click({ force: true });
    await expect(page.getByLabel("رمز التأكيد")).toBeVisible();
    expect(sent).toBe(1);
  });

  test("Turnstile loads only when the reader reaches the form", async ({ page }) => {
    const asked: string[] = [];
    page.on("request", (r) => r.url().startsWith("https://challenges.cloudflare.com") && asked.push(r.url()));
    await standInTurnstile(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    expect(asked).toEqual([]);
    await page.getByLabel("البريد الإلكتروني").focus();
    await expect.poll(() => asked.length).toBe(1);
  });

  test("the site's route refuses a request from another site, or without JSON", async ({ request }) => {
    const other = await request.post("/api/newsletter/subscribe", { headers: { Origin: "https://evil.example" }, data: { email: "a@example.com" } });
    expect(other.status()).toBe(403);
    const form = await request.post("/api/newsletter/subscribe", { headers: { Origin: "http://127.0.0.1:3100" }, form: { email: "a@example.com" } });
    expect(form.status()).toBe(415);
    const unknown = await request.post("/api/newsletter/unsubscribe", { headers: { Origin: "http://127.0.0.1:3100" }, data: {} });
    expect(unknown.status()).toBe(404);
  });
});
