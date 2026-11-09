import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext } from "@playwright/test";

import { fakeApi, webhookSecret } from "./helpers";

/*
 * The platform's webhook (/api/revalidate): only a message signed with the shared secret over "{timestamp}.{body}",
 * at most five minutes old, refreshes the cache, and only what its type names.
 */

const sign = (body: string, timestamp: number, secret = webhookSecret) =>
  `sha256=${createHmac("sha256", secret).update(`${timestamp}.${body}`).digest("hex")}`;

const now = () => Math.floor(Date.now() / 1000);

function send(request: APIRequestContext, body: string, headers: Record<string, string>) {
  // Raw bytes: the body must reach the site exactly as it was signed.
  return request.post("/api/revalidate", { data: Buffer.from(body), headers: { "Content-Type": "application/json", ...headers } });
}

function signed(request: APIRequestContext, message: object, timestamp = now()) {
  const body = JSON.stringify(message);

  return send(request, body, { "X-Raqam-Timestamp": String(timestamp), "X-Raqam-Signature": sign(body, timestamp) });
}

// It changes what the fake API answers for every page: one browser size runs it, and its tests one after another.
test.describe.configure({ mode: "serial" });

test.describe("the platform's webhook", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "runs once, at one size"));

  test("refuses a message without a signature, with a wrong one, or signed with another secret", async ({ request }) => {
    const body = JSON.stringify({ type: "all", change: "refresh", paths: [] });
    const t = now();
    const attempts: Record<string, string>[] = [
      {},
      { "X-Raqam-Timestamp": String(t) },
      { "X-Raqam-Timestamp": String(t), "X-Raqam-Signature": "sha256=" + "0".repeat(64) },
      { "X-Raqam-Timestamp": String(t), "X-Raqam-Signature": sign(body, t, "another-secret") },
      { "X-Raqam-Timestamp": String(t), "X-Raqam-Signature": sign(body, t).replace("sha256=", "sha1=") },
      { "X-Raqam-Timestamp": "soon", "X-Raqam-Signature": sign(body, t) },
    ];
    for (const headers of attempts) {
      const response = await send(request, body, headers);
      expect(response.status(), JSON.stringify(headers)).toBe(401);
      expect(await response.json()).toEqual({ error: { code: "refused" } });
    }
  });

  test("refuses a body changed after signing", async ({ request }) => {
    const t = now();
    const body = JSON.stringify({ type: "home", change: "layout", paths: ["/"] });
    const response = await send(request, body.replace("home", "all"), { "X-Raqam-Timestamp": String(t), "X-Raqam-Signature": sign(body, t) });
    expect(response.status()).toBe(401);
  });

  test("refuses an old copy (more than five minutes) and one dated ahead", async ({ request }) => {
    for (const t of [now() - 301, now() - 3600, now() + 301]) {
      const response = await signed(request, { type: "home", change: "layout", paths: ["/"] }, t);
      expect(response.status(), String(t)).toBe(401);
    }
    // Inside the window it is accepted.
    expect((await signed(request, { type: "home", change: "layout", paths: ["/"] }, now() - 200)).status()).toBe(200);
  });

  test("refreshes the tags each type names; an unknown type refreshes everything", async ({ request }) => {
    const cases: [object, string[]][] = [
      [{ type: "post", id: 300, change: "updated", paths: [] }, ["posts", "home", "post:300"]],
      [{ type: "home", change: "layout", paths: ["/"] }, ["home"]],
      [{ type: "ads", change: "campaign", paths: [] }, ["ads"]],
      [{ type: "currencies", change: "rates_published", paths: ["/currencies"] }, ["currencies"]],
      [{ type: "menus", change: "header", paths: [] }, ["menus"]],
      [{ type: "site", change: "settings", paths: [] }, ["site"]],
      [{ type: "all", change: "character_hidden", id: 3, paths: [] }, ["api"]],
      [{ type: "something_new", paths: [] }, ["api"]],
    ];
    for (const [message, tags] of cases) {
      const response = await signed(request, message);
      expect(response.status()).toBe(200);
      expect(await response.json()).toEqual({ refreshed: tags });
    }
    expect((await send(request, "not json", { "X-Raqam-Timestamp": String(now()), "X-Raqam-Signature": sign("not json", now()) })).status()).toBe(400);
  });

  test("the home page keeps its cached copy until the webhook says it changed", async ({ page, request }) => {
    const home = JSON.parse(readFileSync("tests/fake-api/fixtures/home.json", "utf8"));
    const latest = home.data.find((b: { type: string }) => b.type === "latest");
    const changed = { ...home, data: home.data.map((b: { id: number }) => (b.id === latest.id ? { ...b, title: "آخر الأخبار الآن" } : b)) };

    await page.goto("/");
    await expect(page.getByRole("heading", { level: 2, name: latest.title, exact: true })).toBeVisible();
    try {
      expect((await request.put(`${fakeApi}/__fixtures/home`, { data: changed })).ok()).toBe(true);

      // A wrongly signed message changes nothing.
      const body = JSON.stringify({ type: "home", change: "layout", paths: ["/"] });
      await send(request, body, { "X-Raqam-Timestamp": String(now()), "X-Raqam-Signature": sign(body, now(), "wrong") });
      await page.reload();
      await expect(page.getByRole("heading", { level: 2, name: latest.title, exact: true })).toBeVisible();

      expect((await signed(request, { type: "home", change: "layout", paths: ["/"] })).status()).toBe(200);
      await expect(async () => {
        await page.reload();
        await expect(page.getByRole("heading", { level: 2, name: "آخر الأخبار الآن" })).toBeVisible({ timeout: 1000 });
      }).toPass({ timeout: 15_000 });
    } finally {
      await request.delete(`${fakeApi}/__fixtures/home`);
      await signed(request, { type: "home", change: "layout", paths: ["/"] });
    }
  });
});
