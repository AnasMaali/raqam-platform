import { test as base, expect, type Page } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PATH, startApp, type App, type AppOptions } from './app';

type Fixtures = {
    /** Environment overrides for this test's server (set with test.use). */
    appOptions: AppOptions;
    /** This test's own back end, database and storage. */
    app: App;
    adminPassword: string;
};

export const test = base.extend<Fixtures>({
    appOptions: [{}, { option: true }],
    app: async ({ appOptions }, use) => {
        const app = await startApp(appOptions);
        try {
            await use(app);
        } finally {
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
    await submitPassword(page, ADMIN_EMAIL, password);
    await submitCode(page, app.lastCode());
    await expect(page).toHaveURL(new RegExp(`/${ADMIN_PATH}/?$`));
}
