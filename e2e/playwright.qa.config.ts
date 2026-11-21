import { defineConfig, devices } from '@playwright/test';

import { STATE_FILE } from './qa/global-setup';
import { QA_URL } from './qa/qa-env';
import { SITE_URL } from './qa/site-env';

/*
 * The QA checks (docs/qa/README.md): against the QA environment's running servers (backend/scripts/qa.sh serve, worker
 * and site start) and its database raqam_qa, never the per-test databases of the browser tests. Local only, by hand:
 *   npx playwright test -c playwright.qa.config.ts --project=computer --project=phone-360   the panel
 *   npx playwright test -c playwright.qa.config.ts --project=site-webhook                   the website (all of it)
 * The website's projects run in this order: the map, then the checks, the browser pass and the accessibility check
 * side by side, then the panel's changes reaching the site (last: it changes what the others read). Each needs the
 * map: `--no-deps` runs one again on the map already made.
 */
const computer = { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } };
const site = { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, baseURL: SITE_URL, storageState: undefined };

export default defineConfig({
    testDir: './qa',
    globalSetup: './qa/global-setup.ts',
    globalTeardown: './qa/global-teardown.ts',
    fullyParallel: true,
    workers: 4,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    use: { baseURL: QA_URL, locale: 'ar', storageState: STATE_FILE, trace: 'retain-on-failure' },
    projects: [
        { name: 'computer', testMatch: /panel-crawl/, use: computer },
        { name: 'phone-360', testMatch: /panel-crawl/, use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 780 }, isMobile: true, hasTouch: true } },
        // The database queries of every API endpoint and panel screen, against their budgets (docs/qa/performance.md).
        { name: 'query-budget', testMatch: /query-budget\.spec/, use: computer },
        // The sign-in's request limit, from outside (docs/qa/security.md). Signed out, with an e-mail of its own.
        { name: 'signin-limit', testMatch: /signin-limit\.spec/, use: { ...computer, storageState: undefined } },
        { name: 'site-map', testMatch: /site-map/, use: site },
        { name: 'site-checks', testMatch: /site-checks/, dependencies: ['site-map'], use: site },
        { name: 'site-browser', testMatch: /site-pages/, dependencies: ['site-map'], use: site },
        { name: 'site-axe', testMatch: /site-axe/, dependencies: ['site-map'], use: site },
        // The panel signed in again after the hour the pages take (the session ends after 30 idle minutes), and the site beside it.
        { name: 'panel-session', testMatch: /panel-session\.setup/, dependencies: ['site-checks', 'site-browser', 'site-axe'], use: { storageState: undefined } },
        { name: 'site-webhook', testMatch: /site-webhook/, dependencies: ['panel-session'], use: computer },
    ],
});
