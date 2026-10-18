import { test, expect, panel, signIn } from '../support/fixtures';

// A short idle limit so the real warning dialog can be watched: out after 20 s, warning for the last 12.
test.use({ appOptions: { env: { ADMIN_IDLE_SECONDS: '20', ADMIN_WARN_SECONDS: '12' } } });

const dialog = (page: import('@playwright/test').Page) => page.getByRole('alertdialog');

test.describe('session dialog', () => {
    test('"I am here" is a real request that keeps the session; silence ends it with a message', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);

        await expect(dialog(page)).toBeVisible({ timeout: 15_000 });
        const keepAlive = page.waitForResponse((r) => r.url().endsWith('/session/keep-alive') && r.request().method() === 'POST');
        await dialog(page).getByRole('button', { name: 'أنا هون' }).click();
        expect((await keepAlive).status()).toBe(200);
        await expect(dialog(page)).toBeHidden();

        // The session carried on past the first 20 seconds: still on the panel after the full limit has passed since sign-in.
        await expect(dialog(page)).toBeVisible({ timeout: 15_000 });
        await page.waitForURL(/login\?expired=1/, { timeout: 30_000 });
        await expect(page.getByText('انتهت الجلسة، سجّل الدخول من جديد.')).toBeVisible();

        // The server really ended it: the panel asks for the sign-in again.
        await page.goto(panel(''));
        await expect(page).toHaveURL(/login/);
        expect(app.sql("SELECT string_agg(new_value, ',') FROM audit_log_changes WHERE field = 'logout_reason'")).toContain('idle');
    });

    test('two tabs: activity in one closes the warning in the other, and the end takes both out', async ({ context, page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const second = await context.newPage();
        await second.goto(panel(''));

        await expect(dialog(page)).toBeVisible({ timeout: 15_000 });
        await expect(dialog(second)).toBeVisible({ timeout: 15_000 });

        await dialog(page).getByRole('button', { name: 'أنا هون' }).click();
        await expect(dialog(page)).toBeHidden();
        await expect(dialog(second)).toBeHidden();   // told through the channel, no request of its own

        // Nobody does anything any more: the first tab to reach zero asks the server, and both leave.
        await Promise.all([page.waitForURL(/login\?expired=1/, { timeout: 40_000 }), second.waitForURL(/login\?expired=1/, { timeout: 40_000 })]);
    });
});
