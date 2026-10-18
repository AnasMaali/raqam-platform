import { test, expect, panel, submitPassword, submitCode, signIn } from '../support/fixtures';
import { ADMIN_EMAIL, ADMIN_PATH } from '../support/app';

test.describe('sign-in', () => {
    test('a wrong password and an unknown e-mail get the very same page, and no code is sent', async ({ page, app }) => {
        await submitPassword(page, 'nobody@example.test', 'Some-Password-123');
        const unknown = await page.locator('body').innerText();

        await submitPassword(page, ADMIN_EMAIL, 'Wrong-Password-123');
        const wrong = await page.locator('body').innerText();

        expect(unknown).toContain('أدخل رمز التحقق');
        expect(wrong).toBe(unknown);

        // Neither produced a mail (the mail is sent after the response, so give it time to show up if it were sent).
        await page.waitForTimeout(1500);
        expect(app.codesSent()).toBe(0);
    });

    test('a wrong code is refused, then the right one signs in with hardened cookies', async ({ page, app, adminPassword, context }) => {
        await submitPassword(page, ADMIN_EMAIL, adminPassword);
        const code = await app.lastCode();

        await submitCode(page, code === '000000' ? '111111' : '000000');
        await expect(page.getByText('الرمز غير صحيح أو منتهي الصلاحية.')).toBeVisible();
        await expect(page).toHaveURL(/login\/code/);

        await submitCode(page, code);
        await expect(page).toHaveURL(new RegExp(`/${ADMIN_PATH}/?$`));

        const cookies = await context.cookies();
        for (const name of ['__Host-raqam_session', '__Host-raqam_device']) {
            const cookie = cookies.find((c) => c.name === name);
            expect(cookie, name).toBeDefined();
            expect(cookie).toMatchObject({ httpOnly: true, secure: true, sameSite: 'Strict', path: '/' });
        }

        const reasons = app.sql("SELECT string_agg(coalesce(failure_reason, 'success'), ',' ORDER BY id) FROM login_attempts");
        expect(reasons).toContain('bad_code');
        expect(reasons).toContain('success');
    });

    test('a code is single use: the same code does not open a second session', async ({ page, browser, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const code = await app.lastCode();

        const other = await browser.newContext();
        const second = await other.newPage();
        await second.goto(app.url + panel('/login'));
        await second.getByLabel('البريد الإلكتروني').fill(ADMIN_EMAIL);
        await second.locator('input[id="form.password"]').fill(adminPassword);
        await second.getByRole('button', { name: 'تسجيل الدخول' }).click();
        await second.waitForURL(/login\/code/);
        // A second code is not sent within a minute, and the used one is dead whatever the session.
        await submitCode(second, code);
        await expect(second.getByText('الرمز غير صحيح أو منتهي الصلاحية.')).toBeVisible();
        await other.close();
    });
});
