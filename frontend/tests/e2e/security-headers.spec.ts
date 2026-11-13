import { expect, test, type Page } from "@playwright/test";

import { fakeApi } from "./helpers";

/*
 * The security headers (next.config.ts): on every kind of answer the site gives, each with its exact value (the content
 * security policy as the browser applies it: content-security.spec.ts), and the
 * browser features as the site hands them out: the visitor's position to the site itself, four to the YouTube player
 * and nothing else to anyone, without a single warning in the console.
 */

const youtube = '(self "https://www.youtube-nocookie.com")';
const expected: Record<string, string> = {
  "strict-transport-security": "max-age=31536000; includeSubDomains",
  "x-content-type-options": "nosniff",
  "referrer-policy": "strict-origin-when-cross-origin",
  "permissions-policy": [
    "geolocation=(self)",
    `autoplay=${youtube}`,
    `encrypted-media=${youtube}`,
    `picture-in-picture=${youtube}`,
    `fullscreen=${youtube}`,
    "accelerometer=()",
    "camera=()",
    "display-capture=()",
    "gyroscope=()",
    "magnetometer=()",
    "microphone=()",
    "midi=()",
    "payment=()",
    "usb=()",
    "browsing-topics=()",
  ].join(", "),
  "x-frame-options": "DENY",
  "cross-origin-opener-policy": "same-origin",
  // The platform's pictures and uploaded videos come from the API's origin here (MEDIA_ORIGINS is not set).
  "content-security-policy": [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com",
    "script-src-attr 'none'",
    "style-src 'self' 'unsafe-inline'",
    `img-src 'self' ${new URL(fakeApi).origin} https://i.ytimg.com`,
    "font-src 'self'",
    `media-src ${new URL(fakeApi).origin}`,
    "frame-src https://www.youtube-nocookie.com https://challenges.cloudflare.com",
    "connect-src 'self'",
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "frame-ancestors 'none'",
    "report-uri /api/csp-report",
    "report-to csp",
  ].join("; "),
  "reporting-endpoints": 'csp="/api/csp-report"',
};

/** Warnings about browser features: an unknown name in the header, a frame asking for what it was not handed. */
const featureWarning = /permissions.?policy|feature.?policy|unrecognized feature|allow attribute|allowfullscreen/i;

function watchConsole(page: Page) {
  const warnings: string[] = [];
  page.on("console", (message) => {
    if (featureWarning.test(message.text())) warnings.push(`${message.type()}: ${message.text()}`);
  });

  return warnings;
}

test.describe("the security headers", () => {
  test.beforeEach(({}, info) => test.skip(info.project.name !== "desktop", "the same answers at every size"));

  test("every kind of answer carries all eight, each with its exact value", async ({ request }) => {
    const home = await (await request.get("/")).text();
    const buildFile = /\/_next\/static\/[^"]+\.js/.exec(home)![0];
    const answers = [
      ["a page", await request.get("/")],
      ["a missing page", await request.get("/no-such-page")],
      ["a moved page", await request.get("/news/300/old-slug", { maxRedirects: 0 })],
      ["a route", await request.get("/api/chat")],
      ["a route's refusal", await request.post("/api/chat", { headers: { Origin: "https://evil.example" }, data: {} })],
      ["a feed", await request.get("/feeds/posts")],
      ["a sitemap", await request.get("/sitemap.xml")],
      ["robots.txt", await request.get("/robots.txt")],
      ["the icon", await request.get("/icon.svg")],
      ["a build file", await request.get(buildFile)],
    ] as const;
    expect(answers.map(([, a]) => a.status())).toEqual([200, 404, 301, 200, 403, 200, 200, 200, 200, 200]);
    for (const [name, answer] of answers) {
      const headers = answer.headers();
      for (const [key, value] of Object.entries(expected)) expect(headers[key], `${name}: ${key}`).toBe(value);
      expect(headers["x-powered-by"], name).toBeUndefined();
    }
  });

  test("the browser hands the visitor's position to the site only, and nothing else it does not need", async ({ page }) => {
    const warnings = watchConsole(page);
    const header = (await page.goto("/"))!.headers()["permissions-policy"];
    const allowed = await page.evaluate(() => {
      const policy = (document as unknown as { featurePolicy: { allowsFeature: (f: string) => boolean } }).featurePolicy;
      return Object.fromEntries(["geolocation", "autoplay", "fullscreen", "camera", "microphone", "payment", "accelerometer"].map((f) => [f, policy.allowsFeature(f)]));
    });
    expect(allowed).toEqual({ geolocation: true, autoplay: true, fullscreen: true, camera: false, microphone: false, payment: false, accelerometer: false });
    // A name the browser does not know is dropped (and only some browsers say so in the console): every name is one it knows.
    const known = await page.evaluate(() => (document as unknown as { featurePolicy: { features: () => string[] } }).featurePolicy.features());
    const named = header.split(", ").map((part) => part.split("=")[0]);
    expect(named.length).toBeGreaterThan(10);
    expect(named.filter((name) => !known.includes(name))).toEqual([]);
    await page.waitForLoadState("networkidle");
    expect(warnings).toEqual([]);
  });

  test("the YouTube player gets the four features it needs and no other, without a warning", async ({ page }) => {
    const warnings = watchConsole(page);
    await page.route("https://www.youtube-nocookie.com/**", (route) => route.fulfill({ contentType: "text/html", body: "<!doctype html><title>player</title>" }));
    await page.goto("/video/23");
    await page.getByRole("navigation", { name: "فصول الفيديو" }).getByRole("button").first().click();
    const frame = page.locator("iframe[src*='youtube-nocookie.com']");
    await expect(frame).toHaveAttribute("allow", "autoplay; encrypted-media; picture-in-picture; fullscreen");
    await expect(frame).not.toHaveAttribute("allowfullscreen");
    const isPlayer = (f: { url: () => string }) => f.url().startsWith("https://www.youtube-nocookie.com/");
    await expect.poll(() => page.frames().some(isPlayer)).toBe(true);
    const inside = await page.frames().find(isPlayer)!.evaluate(() => {
      const policy = (document as unknown as { featurePolicy: { allowsFeature: (f: string) => boolean } }).featurePolicy;
      return Object.fromEntries(
        ["autoplay", "encrypted-media", "picture-in-picture", "fullscreen", "accelerometer", "gyroscope", "clipboard-write", "geolocation", "camera"].map((f) => [f, policy.allowsFeature(f)]),
      );
    });
    expect(inside).toEqual({
      autoplay: true,
      "encrypted-media": true,
      "picture-in-picture": true,
      fullscreen: true,
      accelerometer: false,
      gyroscope: false,
      "clipboard-write": false,
      geolocation: false,
      camera: false,
    });
    await page.waitForTimeout(500);
    expect(warnings).toEqual([]);
  });
});
