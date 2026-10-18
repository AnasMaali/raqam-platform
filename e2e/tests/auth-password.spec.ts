import { randomBytes } from 'node:crypto';
import { test, expect, panel, signIn } from '../support/fixtures';
import { ADMIN_EMAIL } from '../support/app';

test('changing the password signs the other session out, and keeps this one', async ({ page, browser, app, adminPassword }) => {
    await signIn(page, app, adminPassword);

    // A second browser (another session) of the same admin. A second code within a minute is refused by design,
    // so the e-mail limits are cleared the way an operator would: admin:unlock.
    app.artisan(['admin:unlock', ADMIN_EMAIL]);
    const otherContext = await browser.newContext({ baseURL: app.url });
    const other = await otherContext.newPage();
    await signIn(other, app, adminPassword);
    await other.goto(panel(''));
    await expect(other).toHaveURL(new RegExp(`${panel('')}/?$`));

    const fresh = 'Nn3-' + randomBytes(18).toString('base64url');
    await page.goto(panel('/password'));
    await page.locator('input[id="form.current_password"]').fill(adminPassword);
    await page.locator('input[id="form.password"]').fill(fresh);
    await page.locator('input[id="form.password_confirmation"]').fill(fresh);
    await page.getByRole('button', { name: 'تغيير كلمة المرور' }).click();
    await expect(page.getByText('تم تغيير كلمة المرور. تم تسجيل الخروج من باقي الأجهزة.')).toBeVisible();

    // This session carries on ...
    await page.goto(panel(''));
    await expect(page).toHaveURL(new RegExp(`${panel('')}/?$`));
    // ... the other one is out on its very next request.
    await other.goto(panel(''));
    await expect(other).toHaveURL(/login/);
    await otherContext.close();

    expect(app.sql("SELECT string_agg(new_value, ',') FROM audit_log_changes WHERE field = 'logout_reason'")).toContain('password_changed');
    expect(app.log()).toContain('Subject:');   // the notification mail went out
});

test('a wrong current password changes nothing', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await page.goto(panel('/password'));
    await page.locator('input[id="form.current_password"]').fill('not-the-password-1');
    await page.locator('input[id="form.password"]').fill('Zz9-' + randomBytes(18).toString('base64url'));
    await page.locator('input[id="form.password_confirmation"]').fill('different');
    await page.getByRole('button', { name: 'تغيير كلمة المرور' }).click();
    await expect(page.getByText('كلمة المرور الحالية غير صحيحة.')).toBeVisible();
    expect(app.sql("SELECT count(*) FROM audit_log WHERE action = 'update' AND subject_table = 'users'")).toBe('0');
});
