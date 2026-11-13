import { defineConfig, devices } from "@playwright/test";

import { site } from "./tests/e2e/helpers";

/*
 * Browser tests of the site at three sizes (375, 768, 1440). The site runs as a production build on port 3100 (run
 * `npm run build` first) and the development lab on port 3200, both reading the fake API on port 4010 (made-up demo
 * content). LAB_URL points the lab tests at a development server already running instead (locally, `npm run dev`).
 * The responsive sweep (320 to 1920, a phone held sideways, 200% zoom) runs once, in the "sweep" project.
 */
const lab = process.env.LAB_URL;
const api = {
  API_BASE_URL: "http://127.0.0.1:4010/api/v1",
  API_SITE_TOKEN: "ci-only-site-token-not-a-secret",
  SITE_URL: site,
  TURNSTILE_SITE_KEY: "1x00000000000000000000AA",
  SITE_WEBHOOK_SECRET: "ci-only-webhook-secret-not-a-secret",
  INDEXNOW_KEY: "ci-only-indexnow-key-0000",
};

// The fake API turns the popup on (tests/fake-api/server.mjs). Every browser starts as a visitor who has just seen it,
// so it never opens over another test; the popup's own tests (ads.spec.ts) start with an empty browser.
const popupSeen = (origin: string) => ({ origin, localStorage: [{ name: "raqam:ad-popup", value: String(Date.now()) }] });

export default defineConfig({
  testDir: "tests/e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",
  use: {
    baseURL: site,
    trace: "retain-on-failure",
    locale: "ar",
    storageState: { cookies: [], origins: [popupSeen("http://127.0.0.1:3100"), popupSeen(lab ?? "http://127.0.0.1:3200")] },
  },
  projects: [
    { name: "phone", testIgnore: /sweep/, use: { ...devices["Desktop Chrome"], viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true } },
    { name: "tablet", testIgnore: /sweep/, use: { ...devices["Desktop Chrome"], viewport: { width: 768, height: 1024 }, hasTouch: true } },
    { name: "desktop", testIgnore: /sweep/, use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
    { name: "sweep", testMatch: /sweep/, use: { ...devices["Desktop Chrome"] } },
  ],
  webServer: [
    // CI starts these two before the tests (the build needs the API; the production checks need the server).
    { command: "node tests/fake-api/server.mjs 4010", url: "http://127.0.0.1:4010/api/v1/site", reuseExistingServer: true },
    { command: "npx next start -p 3100", url: "http://127.0.0.1:3100/", env: api, reuseExistingServer: true, timeout: 60_000 },
    ...(lab ? [] : [{ command: "npx next dev -p 3200", url: "http://127.0.0.1:3200/lab", env: api, reuseExistingServer: !process.env.CI, timeout: 120_000 }]),
  ],
});
