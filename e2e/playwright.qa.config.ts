import { defineConfig, devices } from '@playwright/test';

import { STATE_FILE } from './qa/global-setup';
import { QA_URL } from './qa/qa-env';

/*
 * The QA checks (docs/qa/README.md): against the QA environment's running server (backend/scripts/qa.sh serve) and its
 * database raqam_qa, never the per-test databases of the browser tests. Local only, by hand:
 *   npx playwright test -c playwright.qa.config.ts
 */
export default defineConfig({
    testDir: './qa',
    globalSetup: './qa/global-setup.ts',
    fullyParallel: true,
    workers: 3,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    use: { baseURL: QA_URL, locale: 'ar', storageState: STATE_FILE, trace: 'retain-on-failure' },
    projects: [
        { name: 'computer', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 900 } } },
        { name: 'phone-360', use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 780 }, isMobile: true, hasTouch: true } },
    ],
});
