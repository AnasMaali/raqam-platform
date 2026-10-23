import { writeFileSync } from 'node:fs';
import { test as base, expect, type Page } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PATH, startApp, type App, type AppOptions } from './app';
import { startFakeMarkets, type FakeMarkets } from './markets';
import { startFakeOutside, type FakeOutside } from './outside';
import { startFakeYoutube, type FakeYoutube } from './youtube';

type Fixtures = {
    /** Environment overrides for this test's server (set with test.use). */
    appOptions: AppOptions;
    /** This test's own back end, database and storage. */
    app: App;
    adminPassword: string;
    /** A stand-in for the YouTube API and its pictures; the application under test is pointed at it. */
    youtube: FakeYoutube;
    /** A stand-in for the Bank of Israel and OpenWeather; the application under test is pointed at it. */
    markets: FakeMarkets;
    /** A stand-in for Brevo and Cloudflare Turnstile; the application under test is pointed at it. */
    outside: FakeOutside;
};

export const test = base.extend<Fixtures>({
    appOptions: [{}, { option: true }],
    youtube: async ({}, use) => {
        const youtube = await startFakeYoutube();
        try {
            await use(youtube);
        } finally {
            await youtube.stop();
        }
    },
    markets: async ({}, use) => {
        const markets = await startFakeMarkets();
        try {
            await use(markets);
        } finally {
            await markets.stop();
        }
    },
    outside: async ({}, use) => {
        const outside = await startFakeOutside();
        try {
            await use(outside);
        } finally {
            await outside.stop();
        }
    },
    app: async ({ appOptions, youtube, markets, outside }, use, testInfo) => {
        const app = await startApp({
            ...appOptions,
            env: {
                YOUTUBE_API_KEY: 'e2e-fake-youtube-key', YOUTUBE_API_BASE: youtube.apiBase, YOUTUBE_IMAGE_BASE: youtube.imageBase, YOUTUBE_FETCH_THUMBNAILS: 'true',
                CURRENCY_BOI_BASE: markets.boiBase, OPENWEATHER_API_BASE: markets.owBase, OPENWEATHER_API_KEY: 'e2e-fake-openweather-key',
                TURNSTILE_SECRET_KEY: 'e2e-fake-turnstile-secret', TURNSTILE_VERIFY_URL: outside.turnstileUrl, TURNSTILE_HOSTNAMES: 'raqam.test',
                BREVO_API_KEY: 'e2e-fake-brevo-key', BREVO_LIST_ID: '3', BREVO_API_BASE: outside.brevoBase, BREVO_WEBHOOK_TOKEN: 'e2e-fake-brevo-webhook-token-0123456789abcdef',
                ...appOptions.env,
            },
        });
        try {
            await use(app);
        } finally {
            // A failed test says what the server logged, so a 500 is explained in the report and not lost with the storage directory.
            // The whole application log and the PHP server's own output are kept with the test's results.
            if (testInfo.status !== testInfo.expectedStatus) {
                const errors = app.log().split('\n').filter((line) => /\.(ERROR|CRITICAL|EMERGENCY)/.test(line)).slice(0, 5);
                if (errors.length) console.log(`Server errors of "${testInfo.title}":\n${errors.join('\n')}`);
                await testInfo.attach('e2e.log', { path: keep(testInfo.outputPath('e2e.log'), app.log()), contentType: 'text/plain' });
                await testInfo.attach('php-server.log', { path: keep(testInfo.outputPath('php-server.log'), app.serverLog()), contentType: 'text/plain' });
            }
            await app.stop();
        }
    },
    baseURL: async ({ app }, use) => use(app.url),
    adminPassword: async ({}, use) => use(process.env.E2E_ADMIN_PASSWORD!),
});

export { expect };

function keep(path: string, text: string): string {
    writeFileSync(path, text);
    return path;
}

export const panel = (path = '') => `/${ADMIN_PATH}${path}`;

export async function submitPassword(page: Page, email: string, password: string) {
    await page.goto(panel('/login'));
    await page.getByLabel('البريد الإلكتروني').fill(email);
    await page.locator('input[type=password], input[id="form.password"]').first().fill(password);
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    await page.waitForURL(/login\/code/);
}

export async function submitCode(page: Page, code: string) {
    await page.getByLabel('رمز التحقق').fill(code);
    await page.getByRole('button', { name: 'تأكيد' }).click();
}

/** Password, then the code from the mail log: the real sign-in, as an editor does it. */
export async function signIn(page: Page, app: App, password: string) {
    const before = app.codesSent();
    await submitPassword(page, ADMIN_EMAIL, password);
    await submitCode(page, await app.lastCode(before));
    await expect(page).toHaveURL(new RegExp(`/${ADMIN_PATH}/?$`));
}
