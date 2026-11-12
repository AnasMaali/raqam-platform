import { expect, test, type Page } from "@playwright/test";

import { fakeApi, smallTargets } from "./helpers";

/*
 * The chatbot (18, P1 decision): the button is drawn on the server and moves nothing; the chat's code loads only when
 * it is pressed; the site's route takes requests from the site's own pages only and is never cached; every answer is
 * text, with links only to the site's own pages. Against the fake API's answers (tests/fake-api/server.mjs).
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
  const site = "http://127.0.0.1:3100";

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
