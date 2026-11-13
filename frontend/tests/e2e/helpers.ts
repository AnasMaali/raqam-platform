import { type ChildProcess, spawn } from "node:child_process";

import { expect, type Page } from "@playwright/test";

/** The development lab: its own server (port 3200), or the one LAB_URL names. */
export const labUrl = (path: string) => `${process.env.LAB_URL ?? "http://127.0.0.1:3200"}${path}`;

/** Nothing on the page scrolls sideways. */
export async function expectNoSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, "the page scrolls sideways").toBeLessThanOrEqual(0);
}

/**
 * Every visible control is at least 44 × 44 px. A card's headline is exempt: its link is stretched over the whole card,
 * so the card is the target. Elements inside a strip that scrolls sideways are measured as they are, not as clipped.
 */
export async function smallTargets(page: Page, scope = "body") {
  return page.evaluate((scope) => {
    const small: string[] = [];
    const root = document.querySelector(scope);
    if (!root) return ["no scope"];
    for (const el of root.querySelectorAll<HTMLElement>("a[href], button, label:has(> input)")) {
      const style = getComputedStyle(el);
      // The honeypot field is out of sight and out of reach on purpose (aria-hidden, no tab stop).
      if (style.visibility === "hidden" || el.closest("[hidden], [aria-hidden='true'], dialog:not([open])") || el.getClientRects().length === 0) continue;
      if (el.matches(".sr-only, .sr-only *") && !el.matches(":focus")) continue;
      if (el.className.includes("after:inset-0")) continue;
      // A link inside a sentence is sized by its text (WCAG 2.5.8, inline exception).
      if (el.closest("[data-inline-links]")) continue;
      const box = el.getBoundingClientRect();
      if (box.width < 44 || box.height < 44) small.push(`${el.tagName.toLowerCase()} "${(el.textContent || el.getAttribute("aria-label") || "").trim().slice(0, 30)}" ${Math.round(box.width)}×${Math.round(box.height)}`);
    }
    return small;
  }, scope);
}

/** Use the reader's choice of theme the way the site stores it. */
export async function useTheme(page: Page, theme: "light" | "dark") {
  await page.addInitScript((value) => window.localStorage.setItem("raqam-theme", value), theme);
}

/** The fake platform API the site reads in the tests (tests/fake-api/server.mjs). */
export const fakeApi = "http://127.0.0.1:4010";

/**
 * The site's public address as the test server runs with it (SITE_URL in playwright.config.ts and the CI workflow): the
 * server's own address, so the browser's Origin is the site's. Every test reads it from here.
 */
export const site = "http://127.0.0.1:3100";

/** The webhook secret the test server runs with (playwright.config.ts and the CI workflow). */
export const webhookSecret = process.env.SITE_WEBHOOK_SECRET ?? "ci-only-webhook-secret-not-a-secret";

/** A process of the tests' own, started for one test or one group, and what it printed. */
function started(command: string, args: string[], env: Record<string, string>, ready: string) {
  let output = "";
  const child: ChildProcess = spawn(command, args, { detached: true, env: { ...process.env, ...env } });
  child.stdout!.on("data", (chunk) => (output += chunk));
  child.stderr!.on("data", (chunk) => (output += chunk));
  const exited = new Promise<number | null>((resolve) => child.on("exit", (code) => resolve(code)));

  return {
    exited,
    output: () => output,
    ready: () => expect.poll(async () => (await fetch(ready).catch(() => null))?.status ?? 0, { timeout: 60_000 }).toBe(200),
    stop: () => {
      try {
        process.kill(-child.pid!, "SIGKILL");
      } catch {
        // Already gone.
      }
    },
  };
}

/**
 * Another server of the same build on its own port, with the test server's settings unless `env` says otherwise (the
 * site's address, another fake API).
 */
export function startSite(port: number, env: Record<string, string> = {}) {
  const settings = { API_BASE_URL: `${fakeApi}/api/v1`, API_SITE_TOKEN: "ci-only-site-token-not-a-secret", SITE_URL: site, SITE_WEBHOOK_SECRET: webhookSecret, NEXT_TELEMETRY_DISABLED: "1", ...env };

  return started("node_modules/.bin/next", ["start", "-p", String(port)], settings, `http://127.0.0.1:${port}/robots.txt`);
}

/** Another fake API (tests/fake-api/server.mjs) on its own port, for a test that changes what the platform answers. */
export function startFakeApi(port: number) {
  return started("node", ["tests/fake-api/server.mjs", String(port)], {}, `http://127.0.0.1:${port}/api/v1/site`);
}

/** The pages the responsive sweep and the content security policy check go through: one of each kind, and the lab. */
export const sweepPages = [
  { name: "home", url: "/" },
  { name: "404", url: "/no-such-page" },
  { name: "article", url: encodeURI("/news/300/مشروع-لتخزين-الطاقة-نابلس-يدخل-مرحلة-التشغيل-التجريبي") },
  { name: "episode", url: encodeURI("/podcast/282/الحلقة-10-دروس-مشاريع-تعثرت") },
  { name: "category", url: encodeURI("/category/طاقة") },
  { name: "podcast", url: "/podcast" },
  { name: "history", url: "/history" },
  { name: "awareness", url: "/awareness" },
  { name: "writer, page 2", url: encodeURI("/author/محمد-صالح/page/2") },
  { name: "currencies", url: "/currencies" },
  { name: "dollar", url: "/currencies/usd" },
  { name: "city weather", url: encodeURI("/weather/نابلس") },
  { name: "services", url: "/services" },
  { name: "a service", url: "/services/podcast-episode" },
  { name: "contact", url: "/contact" },
  { name: "about", url: "/about" },
  { name: "video library", url: "/video" },
  { name: "a video", url: "/video/23" },
  { name: "a character", url: "/awareness/characters/raqam" },
  { name: "team", url: "/team" },
  { name: "questions", url: "/faq" },
  { name: "advertise", url: "/advertise" },
  { name: "sitemap", url: "/sitemap" },
  { name: "search", url: `/search?q=${encodeURIComponent("الطاقة")}` },
  { name: "components", url: labUrl("/lab/foundations") },
];
