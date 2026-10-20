import { test as base, expect, type Page } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PATH, startApp, type App, type AppOptions } from './app';
import { startFakeYoutube, type FakeYoutube } from './youtube';

type Fixtures = {
    /** Environment overrides for this test's server (set with test.use). */
    appOptions: AppOptions;
    /** This test's own back end, database and storage. */
    app: App;
    adminPassword: string;
    /** A stand-in for the YouTube API and its pictures; the application under test is pointed at it. */
    youtube: FakeYoutube;
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
    app: async ({ appOptions, youtube }, use, testInfo) => {
        const app = await startApp({
            ...appOptions,
            env: {
                YOUTUBE_API_KEY: 'e2e-fake-youtube-key', YOUTUBE_API_BASE: youtube.apiBase, YOUTUBE_IMAGE_BASE: youtube.imageBase, YOUTUBE_FETCH_THUMBNAILS: 'true',
                ...appOptions.env,
            },
        });
        try {
            await use(app);
        } finally {
            // A failed test says what the server logged, so a 500 is explained in the report and not lost with the storage directory.
            if (testInfo.status !== testInfo.expectedStatus) {
                const errors = app.log().split('\n').filter((line) => /\.(ERROR|CRITICAL|EMERGENCY)/.test(line)).slice(0, 5);
                if (errors.length) console.log(`Server errors of "${testInfo.title}":\n${errors.join('\n')}`);
            }
            await app.stop();
        }
    },
    baseURL: async ({ app }, use) => use(app.url),
    adminPassword: async ({}, use) => use(process.env.E2E_ADMIN_PASSWORD!),
});

export { expect };

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
