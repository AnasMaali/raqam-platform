import { expect, test } from '@playwright/test';

import { panel, psql } from './qa-env';

/*
 * The sign-in's request limit on the running QA environment, from outside (QA-8, docs/qa/security.md): five password
 * attempts a minute for one e-mail from one address, then the form is refused without the password being checked: it
 * stays on the sign-in page, and login_attempts holds one 'throttled' row a minute. A made-up e-mail of its own each run: the QA admin is never locked out.
 */
test('the sixth password in a minute for one e-mail is refused unchecked', async ({ browser }) => {
    const email = `limit-${Date.now()}@example.org`;
    const context = await browser.newContext({ locale: 'ar', storageState: undefined });
    const page = await context.newPage();
    const attempt = async (i: number) => {
        await page.goto(panel('/login'));
        await page.getByLabel('البريد الإلكتروني').fill(email);
        await page.locator('input[type=password]').first().fill(`Wrong-password-${i}`);
        await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    };
    // The first five go on to the code page, as a right password would.
    for (let i = 0; i < 5; i++) {
        await attempt(i);
        await page.waitForURL(/login\/code/);
    }
    // The sixth and seventh stay on the sign-in form, refused.
    for (let i = 5; i < 7; i++) {
        await attempt(i);
        await expect(page.getByRole('button', { name: 'تسجيل الدخول' })).toBeEnabled();
        await page.waitForTimeout(1000);
        expect(new URL(page.url()).pathname, `attempt ${i + 1}`).toBe(panel('/login'));
    }
    await context.close();

    const rows = psql(`SELECT coalesce(failure_reason, 'success') FROM login_attempts WHERE email = '${email}' ORDER BY id`).split('\n').filter(Boolean);
    expect(rows.slice(0, 5)).toEqual(Array(5).fill('bad_credentials'));
    // The refused ones are written once a minute, not once each.
    expect(rows.slice(5)).toEqual(['throttled']);
});
