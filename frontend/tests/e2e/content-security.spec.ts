import { type ChildProcess, spawn } from "node:child_process";
import { readFileSync } from "node:fs";

import { expect, test, type Locator, type Page } from "@playwright/test";

import { fakeApi, sweepPages } from "./helpers";

/*
 * The content security policy (next.config.ts) as the browser applies it: not one violation on any page of the sweep,
 * nor while a visitor plays a video, opens the chat, meets the popup or the phone's bar, sends the contact form or
 * asks for the nearest city. Code slipped into a page as an event handler, or a script from elsewhere, does not run
 * and is reported. The reports' route (/api/csp-report) keeps one line for each, and nothing about the visitor.
 */

type City = { slug: string; latitude: number; longitude: number };
const nablus = (JSON.parse(readFileSync("tests/fake-api/fixtures/weather.json", "utf8")).data as City[]).find((c) => c.slug === "نابلس")!;

/** Violations the page meets, in any of its frames: the browser's event, and its line in the console. */
async function watchViolations(page: Page) {
  const found: string[] = [];
  await page.exposeBinding("__cspViolation", (_source, text: string) => found.push(text));
  await page.addInitScript(() => {
    document.addEventListener("securitypolicyviolation", (e) =>
      (window as unknown as { __cspViolation: (t: string) => void }).__cspViolation(`${e.effectiveDirective} blocked ${e.blockedURI} on ${location.href}`),
    );
  });
  page.on("console", (message) => {
    if (/content security policy|refused to (load|execute|frame|connect|apply)/i.test(message.text())) found.push(`console: ${message.text()}`);
  });

  return found;
}

/**
 * Stand-ins for what the site loads from elsewhere, so nothing leaves the machine: Turnstile's script draws its frame
 * from challenges.cloudflare.com as the real one does and passes at once; YouTube's player and a YouTube picture.
 */
async function standIns(page: Page) {
  await page.route("https://challenges.cloudflare.com/**", (route) =>
    route.request().url().includes("/turnstile/v0/api.js")
      ? route.fulfill({
          contentType: "text/javascript",
          body: `let o; window.turnstile = { render: (el, opts) => { o = opts; const f = document.createElement("iframe"); f.src = "https://challenges.cloudflare.com/cdn-cgi/challenge-platform/turnstile/frame"; f.title = "Turnstile"; el.append(f); setTimeout(() => o.callback("pass:" + o.action), 50); return "w1"; }, reset: () => setTimeout(() => o.callback("pass:" + o.action), 50) };`,
        })
      : route.fulfill({ contentType: "text/html", body: "<!doctype html><title>challenge</title>" }),
  );
  await page.route("https://www.youtube-nocookie.com/**", (route) => route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }));
  await page.route("https://i.ytimg.com/**", (route) =>
    route.fulfill({ contentType: "image/svg+xml", body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="360"><rect width="480" height="360" fill="#124fff"/></svg>' }),
  );
}

/** Down the page to its end, so every picture that waits to be seen loads. */
async function scrollThrough(page: Page) {
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 600) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 50));
    }
  });
  await page.waitForLoadState("networkidle");
}

const loaded = (img: Locator) => expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);

test.describe("the content security policy", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "the same policy at every size; the phone's bar is opened at 375 below"));

  test("not one violation on any page of the sweep, down to its end", async ({ page }) => {
    test.setTimeout(240_000);
    const found = await watchViolations(page);
    await standIns(page);
    for (const { name, url } of sweepPages) {
      await page.goto(url);
      await scrollThrough(page);
      expect(found, name).toEqual([]);
    }
  });

  test("a video: YouTube's player, a picture from YouTube, and a video uploaded to the platform", async ({ page }) => {
    const found = await watchViolations(page);
    await standIns(page);

    await page.goto("/video/23");
    await page.getByRole("button", { name: /^شغّل الفيديو/ }).click();
    await expect.poll(() => page.frames().some((f) => f.url().startsWith("https://www.youtube-nocookie.com/"))).toBe(true);

    await page.goto("/video/9101");
    const picture = page.locator("main img[src^='https://i.ytimg.com/']");
    await expect(picture).toHaveCount(1);
    await loaded(picture);

    await page.goto("/video/9102");
    const asked = page.waitForRequest(`${fakeApi}/media/test-upload.mp4`);
    // Not awaited: the stand-in file is no video, and its play() never settles.
    await page.locator("main video").evaluate((v: HTMLVideoElement) => void v.play().catch(() => undefined));
    await asked;

    await page.waitForTimeout(300);
    expect(found).toEqual([]);
  });

  test("the chat: opened, asked and answered", async ({ page }) => {
    const found = await watchViolations(page);
    await page.goto("/");
    await page.locator("[data-chat-launcher]").click();
    const chat = page.getByRole("dialog", { name: "المساعد" });
    await chat.getByLabel("سؤالك").fill("ما هو سعر الدولار؟");
    await chat.getByLabel("سؤالك").press("Enter");
    await expect(chat.locator("[data-chat-answer], [data-chat-problem]")).toHaveCount(1);
    expect(found).toEqual([]);
  });

  test.describe("the floating ads", () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    async function scrollUntil(page: Page, shown: Locator) {
      await expect(async () => {
        await page.mouse.wheel(0, 300);
        await page.evaluate(() => window.scrollBy(0, 300));
        await expect(shown).toBeVisible({ timeout: 1500 });
      }).toPass({ timeout: 15_000 });
    }

    test("the popup at 1440 and the phone's bar at 375, each with its picture", async ({ page }) => {
      const found = await watchViolations(page);
      await page.goto("/");
      const popup = page.locator("dialog[data-ad-popup]");
      await scrollUntil(page, popup);
      await loaded(popup.locator("img"));

      await page.setViewportSize({ width: 375, height: 812 });
      await page.goto("/faq");
      const bar = page.locator("[data-ad-bar-box]");
      await scrollUntil(page, bar);
      await loaded(bar.locator("img"));
      expect(found).toEqual([]);
    });
  });

  test("the contact form, with Turnstile's script and frame", async ({ page }) => {
    const found = await watchViolations(page);
    await standIns(page);
    await page.goto("/contact");
    await page.getByLabel("الاسم").fill("قارئ تجريبي");
    await page.getByLabel("البريد الإلكتروني").fill("reader@example.com");
    await page.getByLabel("الموضوع").fill("سؤال");
    await page.getByLabel("الرسالة").fill("نص الرسالة التجريبية.");
    // The check is drawn once the visitor reaches the form (and goes with the form once it is sent).
    await expect.poll(() => page.frames().some((f) => f.url().startsWith("https://challenges.cloudflare.com/"))).toBe(true);
    await page.getByRole("button", { name: "أرسل الرسالة" }).click();
    await expect(page.getByText("وصلت رسالتك.", { exact: false })).toBeVisible();
    expect(found).toEqual([]);
  });

  test.describe("«أقرب مدينة لي»", () => {
    test.use({ permissions: ["geolocation"], geolocation: { latitude: nablus.latitude, longitude: nablus.longitude } });

    test("the position allowed, the nearest city's page", async ({ page }) => {
      const found = await watchViolations(page);
      await page.goto("/weather");
      await page.getByRole("button", { name: "أقرب مدينة لي" }).click();
      await expect(page).toHaveURL(new RegExp(`${encodeURI(`/weather/${nablus.slug}`)}$`));
      await page.waitForLoadState("networkidle");
      expect(found).toEqual([]);
    });
  });

  test("code slipped into a page does not run: an event handler, and a script from elsewhere; both are reported", async ({ page }) => {
    const found = await watchViolations(page);
    await page.route("https://scripts.example/**", (route) => route.fulfill({ contentType: "text/javascript", body: "window.__foreign = true;" }));
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    await page.evaluate(() => {
      document.body.insertAdjacentHTML("beforeend", `<img src="/no-such-picture.png" onerror="window.__handler = true" alt="">`);
      const script = document.createElement("script");
      script.src = "https://scripts.example/x.js";
      document.head.append(script);
    });
    await expect.poll(() => found.filter((f) => !f.startsWith("console:")).length).toBe(2);
    expect(found.filter((f) => !f.startsWith("console:")).sort()).toEqual([
      "script-src-attr blocked inline on http://127.0.0.1:3100/",
      "script-src-elem blocked https://scripts.example/x.js on http://127.0.0.1:3100/",
    ]);
    expect(await page.evaluate(() => ({ handler: "__handler" in window, foreign: "__foreign" in window }))).toEqual({ handler: false, foreign: false });
  });
});

/*
 * The reports' route, against a server of its own (the same build) whose log the test reads.
 */
test.describe("/api/csp-report", () => {
  test.describe.configure({ mode: "serial" });
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "a route, the same at every size"));

  const port = 3101;
  const base = `http://127.0.0.1:${port}`;
  let server: ChildProcess | null = null;
  let log = "";

  test.beforeAll(async ({}, info) => {
    if (info.project.name !== "desktop") return;
    server = spawn("node_modules/.bin/next", ["start", "-p", String(port)], {
      detached: true,
      env: { ...process.env, API_BASE_URL: `${fakeApi}/api/v1`, API_SITE_TOKEN: "ci-only-site-token-not-a-secret", SITE_URL: "https://example.org", NEXT_TELEMETRY_DISABLED: "1" },
    });
    server.stdout!.on("data", (chunk) => (log += chunk));
    server.stderr!.on("data", (chunk) => (log += chunk));
    await expect.poll(async () => (await fetch(`${base}/robots.txt`).catch(() => null))?.status ?? 0, { timeout: 60_000 }).toBe(200);
  });

  test.afterAll(() => {
    if (server?.pid) process.kill(-server.pid, "SIGKILL");
  });

  /** The log's report lines written while `act` ran. */
  async function linesOf(act: () => Promise<void>) {
    const from = log.length;
    await act();
    await new Promise((r) => setTimeout(r, 200));

    return log.slice(from).split("\n").filter((line) => line.includes("[csp]"));
  }

  const visitor = { "User-Agent": "VisitorBrowser/7.7 marker-ua", "X-Forwarded-For": "203.0.113.77", "CF-Connecting-IP": "198.51.100.88" };

  test("a report-uri report: 204, never stored, one line without the query, the visitor's address or browser", async ({ request }) => {
    const lines = await linesOf(async () => {
      const answer = await request.post(`${base}/api/csp-report`, {
        headers: { ...visitor, "Content-Type": "application/csp-report" },
        data: JSON.stringify({
          "csp-report": {
            "document-uri": "https://example.org/search?q=private-words#x",
            "effective-directive": "img-src",
            "violated-directive": "img-src",
            "blocked-uri": "https://pictures.example/a.png?token=secret-token",
          },
        }),
      });
      expect(answer.status()).toBe(204);
      expect(answer.headers()["cache-control"]).toBe("no-store");
    });
    expect(lines).toHaveLength(1);
    expect(lines[0]).toMatch(/\[csp\] img-src blocked https:\/\/pictures\.example\/a\.png on https:\/\/example\.org\/search$/);
    for (const kept of ["private-words", "secret-token", "203.0.113.77", "198.51.100.88", "marker-ua"]) expect(log).not.toContain(kept);
  });

  test("report-to reports: one line for each CSP report, other kinds left out", async ({ request }) => {
    const lines = await linesOf(async () => {
      const answer = await request.post(`${base}/api/csp-report`, {
        headers: { ...visitor, "Content-Type": "application/reports+json" },
        data: JSON.stringify([
          { type: "csp-violation", url: "https://example.org/", body: { documentURL: "https://example.org/news/1/x", effectiveDirective: "script-src-attr", blockedURL: "inline" } },
          { type: "csp-violation", url: "https://example.org/", body: { documentURL: "https://example.org/", effectiveDirective: "frame-src", blockedURL: "https://frames.example/w?id=1" } },
          // Another kind of report, even one shaped like a CSP report, is not a violation.
          { type: "intervention", url: "https://example.org/", body: { documentURL: "https://example.org/", effectiveDirective: "img-src", blockedURL: "https://not-csp.example/" } },
        ]),
      });
      expect(answer.status()).toBe(204);
    });
    expect(lines).toHaveLength(2);
    expect(lines[0]).toMatch(/\[csp\] script-src-attr blocked inline on https:\/\/example\.org\/news\/1\/x$/);
    expect(lines[1]).toMatch(/\[csp\] frame-src blocked https:\/\/frames\.example\/w on https:\/\/example\.org\/$/);
  });

  test("only the two kinds of CSP report, at most 8 KB, and nothing else is written", async ({ request }) => {
    const report = (extra = "") =>
      JSON.stringify({ "csp-report": { "document-uri": "https://example.org/", "effective-directive": "img-src", "blocked-uri": `https://pictures.example/${extra}` } });
    const lines = await linesOf(async () => {
      for (const type of ["application/json", "text/plain", "application/x-www-form-urlencoded"]) {
        const answer = await request.post(`${base}/api/csp-report`, { headers: { "Content-Type": type }, data: report() });
        expect(answer.status(), type).toBe(415);
        expect(answer.headers()["cache-control"], type).toBe("no-store");
      }
      expect((await request.post(`${base}/api/csp-report`, { headers: { "Content-Type": "application/csp-report" }, data: report("x".repeat(8300)) })).status()).toBe(413);
      // A body sent in pieces (a stream: no Content-Length): the route counts what arrives.
      const pieces = new ReadableStream({
        start(controller) {
          const text = new TextEncoder().encode(report("x".repeat(1000)).slice(0, 1000));
          for (let i = 0; i < 9; i++) controller.enqueue(text);
          controller.close();
        },
      });
      const streamed = await fetch(`${base}/api/csp-report`, { method: "POST", headers: { "Content-Type": "application/csp-report" }, body: pieces, duplex: "half" } as RequestInit);
      expect(streamed.status).toBe(413);
      expect((await request.post(`${base}/api/csp-report`, { headers: { "Content-Type": "application/csp-report" }, data: "{not json" })).status()).toBe(400);
      expect((await request.post(`${base}/api/csp-report`, { headers: { "Content-Type": "application/csp-report" }, data: JSON.stringify({ other: 1 }) })).status()).toBe(400);
      expect((await request.post(`${base}/api/csp-report`, { headers: { "Content-Type": "application/reports+json" }, data: JSON.stringify([{ type: "deprecation", body: {} }]) })).status()).toBe(400);
      expect((await request.get(`${base}/api/csp-report`)).status()).toBe(405);
    });
    expect(lines).toEqual([]);
  });
});
