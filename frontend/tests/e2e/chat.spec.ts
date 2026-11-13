import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";

import { expect, test, type APIRequestContext, type Page } from "@playwright/test";

import { fakeApi, site, smallTargets, startFakeApi, startSite, webhookSecret } from "./helpers";

/*
 * The chatbot (18, P1 decision): the button is drawn on the server and moves nothing; the chat's code loads only when
 * it is pressed; the site's route takes requests from the site's own pages only and is never cached; every answer is
 * text, with links only to the site's own pages, and WhatsApp only to the site's own number. Against the fake API's
 * answers (tests/fake-api/server.mjs).
 */

const launcher = (page: Page) => page.locator("[data-chat-launcher]");
const chat = (page: Page) => page.getByRole("dialog", { name: "المساعد" });
// Only the window's code carries this attribute: the button's own code does not.
const windowMarker = "data-chat-log";

async function openChat(page: Page) {
  await page.goto("/");
  await launcher(page).click();
  await expect(chat(page).getByLabel("سؤالك")).toBeVisible();
}

async function ask(page: Page, question: string) {
  const answers = chat(page).locator("[data-chat-answer], [data-chat-problem]");
  const before = await answers.count();
  await chat(page).getByLabel("سؤالك").fill(question);
  await chat(page).getByLabel("سؤالك").press("Enter");
  await expect(answers).toHaveCount(before + 1);

  return answers.last();
}

test("the button is in the page's HTML, and the chat's code and settings load only when it is pressed", async ({ page, request }) => {
  expect(await (await request.get("/")).text()).toContain("data-chat-launcher");

  const scripts: { url: string; body: string }[] = [];
  const asked: string[] = [];
  page.on("request", (r) => r.url().includes("/api/chat") && asked.push(r.url()));
  page.on("response", async (r) => {
    if (r.request().resourceType() === "script") scripts.push({ url: r.url(), body: await r.text().catch(() => "") });
  });
  await page.goto("/");
  await page.waitForLoadState("load");
  await page.waitForTimeout(1500);
  expect(scripts.length).toBeGreaterThan(0);
  expect(scripts.filter((s) => s.body.includes(windowMarker)).map((s) => s.url), "chat code before the press").toEqual([]);
  expect(asked, "a chat request before the press").toEqual([]);

  const loaded = scripts.length;
  await launcher(page).click();
  await expect(chat(page).getByText("أهلاً بك! أنا المساعد.")).toBeVisible();
  await expect.poll(() => scripts.slice(loaded).filter((s) => s.body.includes(windowMarker)).length).toBe(1);
  expect(asked.length).toBe(1);
});

test("the button, opening the chat and an answer move nothing on the page (CLS 0)", async ({ page }) => {
  await page.addInitScript(() => {
    const record = window as unknown as { shifted: number; moved: string[] };
    record.shifted = 0;
    record.moved = [];
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries() as (PerformanceEntry & { value: number; sources?: { node?: Node }[] })[]) {
        record.shifted += entry.value;
        for (const source of entry.sources ?? []) {
          const node = source.node as HTMLElement | undefined;
          record.moved.push(`${node?.nodeName ?? "?"}.${String(node?.className ?? "").slice(0, 60)} at ${Math.round(entry.startTime)} ms`);
        }
      }
    }).observe({ type: "layout-shift", buffered: true });
  });
  await openChat(page);
  await chat(page).getByRole("button", { name: "خدماتنا وأسعارها" }).click();
  await expect(chat(page).locator("[data-chat-answer]")).toHaveCount(1);
  await page.keyboard.press("Escape");
  await expect(chat(page)).toBeHidden();
  await page.waitForTimeout(500);
  const { shifted, moved } = await page.evaluate(() => {
    const record = window as unknown as { shifted: number; moved: string[] };
    return { shifted: record.shifted, moved: record.moved };
  });
  expect(shifted, moved.join("; ")).toBe(0);
});

test("an answer with markup is shown as text: nothing of it runs", async ({ page }) => {
  await openChat(page);
  const answer = await ask(page, "أرسل سكربت");
  await expect(answer).toContainText('<script>window.__chatRan = 1</script><img src="x" onerror="window.__chatRan = 2">جواب فيه كود');
  await expect(answer.locator("script, img")).toHaveCount(0);
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => (window as unknown as { __chatRan?: number }).__chatRan)).toBeUndefined();
});

test("only the site's own pages become links; javascript: and other sites stay text", async ({ page }) => {
  await openChat(page);
  const answer = await ask(page, "أعطني رابط");
  const hrefs = await answer.locator("a").evaluateAll((links) => links.map((a) => a.getAttribute("href")));
  // /about from the answer's text, the site's own absolute link as its path, and /services.
  expect(hrefs).toEqual(["/about", "/news/1", "/services"]);
  await expect(answer.getByText("اضغط هنا", { exact: true })).toBeVisible();
  await expect(answer.getByText("موقع آخر", { exact: true })).toBeVisible();
  for (const name of ["اضغط هنا", "موقع آخر", "هذا", "الموقع الآخر", "ذاك"]) await expect(answer.getByRole("link", { name, exact: true })).toHaveCount(0);
  // A WhatsApp link that is not wa.me is dropped.
  await expect(answer.getByRole("link", { name: "كلّمنا على واتساب" })).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as { __chatRan?: number }).__chatRan)).toBeUndefined();
});

test("a question without an answer offers the closest page and WhatsApp; the conversation and the page go with each message", async ({ page }) => {
  const sent: Record<string, unknown>[] = [];
  page.on("request", (r) => r.url().endsWith("/api/chat") && r.method() === "POST" && sent.push(r.postDataJSON()));
  await openChat(page);
  const answer = await ask(page, "سؤال لا جواب له");
  await expect(answer.getByRole("link", { name: "آخر الأخبار" })).toHaveAttribute("href", "/news");
  const whatsapp = answer.getByRole("link", { name: "كلّمنا على واتساب" });
  await expect(whatsapp).toHaveAttribute("href", /^https:\/\/wa\.me\/15555550123\?text=/);
  await expect(whatsapp).toHaveAttribute("rel", "noopener noreferrer");

  await chat(page).getByRole("button", { name: "خدماتنا وأسعارها" }).click();
  await expect(chat(page).locator("[data-chat-answer]")).toHaveCount(2);
  expect(sent[0]).toMatchObject({ message: "سؤال لا جواب له", conversation: null, path: "/", website: "" });
  expect(sent[1]).toMatchObject({ button: 1, conversation: "test-conversation-1", path: "/" });
  // A followed site link closes the chat.
  await chat(page).getByRole("link", { name: "صفحة الخدمات" }).click();
  await expect(page).toHaveURL(/\/services$/);
  await expect(chat(page)).toBeHidden();
});

test("Escape closes the chat and the focus goes back to its button; opening it again keeps the conversation", async ({ page }) => {
  await openChat(page);
  await ask(page, "سؤال لا جواب له");
  await page.keyboard.press("Escape");
  await expect(chat(page)).toBeHidden();
  await expect(launcher(page)).toBeFocused();
  await expect(launcher(page)).toHaveAttribute("aria-expanded", "false");
  await launcher(page).click();
  await expect(chat(page).locator("[data-chat-visitor]")).toHaveText(["أنت: سؤال لا جواب له"]);
});

test("the platform's own message is shown when the visitor sends too much", async ({ page }) => {
  await openChat(page);
  const problem = await ask(page, "رسائل كثيرة");
  await expect(problem).toHaveText("أرسلت رسائل كثيرة. انتظر دقيقة ثم أعد المحاولة.");
});

test("the window: the whole screen on a phone, a panel from 768 px; every control at least 44 px", async ({ page }) => {
  await openChat(page);
  await ask(page, "أعطني رابط");
  const box = (await chat(page).boundingBox())!;
  const viewport = page.viewportSize()!;
  if (viewport.width < 768) expect([box.x, box.y, box.width, box.height]).toEqual([0, 0, viewport.width, viewport.height]);
  else expect(box.width).toBe(384);
  expect(await smallTargets(page, "#chat-window")).toEqual([]);
});

test.describe("the site's route", () => {
  test("a request from another site gets 403, never cached", async ({ request }) => {
    for (const answer of [
      await request.post("/api/chat", { headers: { Origin: "https://evil.example" }, data: { message: "مرحبا" } }),
      await request.post("/api/chat", { data: { message: "مرحبا" } }),
      await request.get("/api/chat", { headers: { Origin: "https://evil.example" } }),
      await request.get("/api/chat", { headers: { "Sec-Fetch-Site": "cross-site" } }),
    ]) {
      expect(answer.status()).toBe(403);
      expect(answer.headers()["cache-control"]).toContain("no-store");
    }
  });

  test("from the site: the token and the visitor's address are added by the server only; the answer has no markup and is never cached", async ({ request }) => {
    const message = `سؤال من الموقع ${Date.now()}`;
    const answer = await request.post("/api/chat", { headers: { Origin: site, "X-Forwarded-For": "203.0.113.7" }, data: { message, path: "/news", website: "" } });
    expect(answer.status()).toBe(200);
    expect(answer.headers()["cache-control"]).toContain("no-store");
    const text = await answer.text();
    expect(text).not.toContain("ci-only-site-token");
    const body = JSON.parse(text);
    expect(Object.keys(body.data.reply).sort()).toEqual(["buttons", "kind", "links", "text", "whatsapp"]);

    const seen = (await (await request.get(`${fakeApi}/__chat?message=${encodeURIComponent(message)}`)).json()).last;
    expect(seen.visitor).toEqual({ ip: "203.0.113.7", token: "ci-only-site-token-not-a-secret" });
    expect(seen.body).toEqual({ website: "", message, conversation: null, path: "/news" });

    const settings = await request.get("/api/chat");
    expect(settings.status()).toBe(200);
    expect(settings.headers()["cache-control"]).toContain("no-store");
  });

  test("a message over 500 characters, or no JSON, is refused before the platform", async ({ request }) => {
    const long = await request.post("/api/chat", { headers: { Origin: site }, data: { message: "س".repeat(501) } });
    expect(long.status()).toBe(422);
    const form = await request.post("/api/chat", { headers: { Origin: site }, form: { message: "مرحبا" } });
    expect(form.status()).toBe(415);
  });
});

/** What the site's route keeps of the WhatsApp link the platform's answer offers ("واتساب <link>" to the fake API). */
async function whatsappKept(request: APIRequestContext, offered: string, base = site) {
  const answer = await request.post(`${base}/api/chat`, { headers: { Origin: site }, data: { message: `واتساب ${offered}` } });
  expect(answer.status(), offered).toBe(200);

  return (await answer.json()).data.reply.whatsapp as string | null;
}

const ready = `?text=${encodeURIComponent("مرحباً، سؤالي: كم السعر؟")}`;

test.describe("WhatsApp: the site's own number only", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "the route, the same at every size"));

  test("wa.me with the number /site gives, with a ready message or without, is kept", async ({ request }) => {
    // The fake API's /site: +15555550123.
    for (const link of ["https://wa.me/15555550123", `https://wa.me/15555550123${ready}`]) expect(await whatsappKept(request, link)).toBe(link);
  });

  test("another number, address or part is no link", async ({ request }) => {
    const refused = [
      `https://wa.me/15555550999${ready}`,
      "https://wa.me/155555501234",
      "https://wa.me/1555555012",
      "https://wa.me/+15555550123",
      "https://wa.me/15555550123/x",
      "https://wa.me/15555550123?text=x&phone=15555550999",
      "https://wa.me/15555550123?phone=15555550999",
      "https://wa.me/15555550123?text=x&text=y",
      "https://wa.me/15555550123#x",
      "http://wa.me/15555550123",
      "https://wa.me:8443/15555550123",
      "https://someone@wa.me/15555550123",
      "https://wa.me.evil.example/15555550123",
      "https://api.whatsapp.com/send?phone=15555550123",
      "javascript:alert(1)//wa.me/15555550123",
    ];
    for (const link of refused) expect(await whatsappKept(request, link), link).toBeNull();
  });
});

test.describe("WhatsApp follows the number the platform's /site gives", () => {
  test.describe.configure({ mode: "serial" });
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "the route, the same at every size"));

  // A fake API and a server of their own: the number changes for them only.
  const api = 4011;
  const port = 3120;
  const base = `http://127.0.0.1:${port}`;
  const fixture = JSON.parse(readFileSync("tests/fake-api/fixtures/site.json", "utf8"));
  const withNumber = (whatsapp: string | null) => ({
    ...fixture,
    data: { ...fixture.data, contact: { ...fixture.data.contact, whatsapp, whatsapp_url: whatsapp ? `https://wa.me/${whatsapp.slice(1)}` : null } },
  });
  let running: { stop: () => void }[] = [];

  test.beforeAll(async ({ request }, info) => {
    if (info.project.name !== "desktop") return;
    const fake = startFakeApi(api);
    running.push(fake);
    await fake.ready();
    expect((await request.put(`http://127.0.0.1:${api}/__fixtures/site`, { data: withNumber("+15555550999") })).ok()).toBe(true);
    const server = startSite(port, { API_BASE_URL: `http://127.0.0.1:${api}/api/v1` });
    running.push(server);
    await server.ready();
  });

  test.afterAll(() => {
    running.forEach((p) => p.stop());
    running = [];
  });

  test("another number in /site: its link is kept, the old one's is not", async ({ request }) => {
    expect(await whatsappKept(request, `https://wa.me/15555550999${ready}`, base)).toBe(`https://wa.me/15555550999${ready}`);
    expect(await whatsappKept(request, `https://wa.me/15555550123${ready}`, base)).toBeNull();
  });

  test("no number in /site: no WhatsApp link at all, once the platform says the settings changed", async ({ request }) => {
    expect((await request.put(`http://127.0.0.1:${api}/__fixtures/site`, { data: withNumber(null) })).ok()).toBe(true);
    const body = JSON.stringify({ type: "site", change: "settings", paths: [] });
    const t = Math.floor(Date.now() / 1000);
    const signature = `sha256=${createHmac("sha256", webhookSecret).update(`${t}.${body}`).digest("hex")}`;
    const told = await request.post(`${base}/api/revalidate`, { data: Buffer.from(body), headers: { "Content-Type": "application/json", "X-Raqam-Timestamp": String(t), "X-Raqam-Signature": signature } });
    expect(told.status()).toBe(200);
    await expect.poll(() => whatsappKept(request, `https://wa.me/15555550999${ready}`, base), { timeout: 15_000 }).toBeNull();
    expect(await whatsappKept(request, "https://wa.me/15555550999", base)).toBeNull();
  });
});
