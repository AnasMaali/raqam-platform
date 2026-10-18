import { test, expect, signIn } from '../support/fixtures';
import { createPost } from '../support/panel';
test('peek', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await createPost(page, 'خبر', 'خبر ناقص');
    await page.getByRole('button', { name: 'نشر الآن' }).click();
    await page.getByRole('button', { name: 'تأكيد' }).click();
    await page.waitForTimeout(3000);
    await page.screenshot({ path: 'test-results/peek.png' });
    console.log('TEXTS', await page.locator('[role=alert], .fi-no-notification, .fi-fo-field-wrp-error-message, [wire\\:key*=error]').allInnerTexts());
});
