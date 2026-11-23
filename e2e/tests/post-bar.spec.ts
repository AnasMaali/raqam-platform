import type { Page } from '@playwright/test';
import { test, expect, signIn } from '../support/fixtures';
import { afterLivewire, confirmDialog, createPost, fillRequired, publishedNews, uploadImage } from '../support/panel';

/*
 * The bar under the editor's form (panel phase 3): it stays on screen, it says whether the editor's changes are saved,
 * Ctrl+S saves without publishing, and on a phone the publishing section comes before the text.
 */

test('the bar says when changes are not saved, Ctrl+S saves a draft as a draft, and leaving asks first', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    const id = await createPost(page, 'خبر', 'مسودة الشريط');
    const state = page.locator('.raqam-save-state');
    const unsaved = state.locator('.raqam-save-state-dirty');

    await expect(state).toContainText('مسودة');
    await expect(state).toContainText('لا تغييرات غير محفوظة', { useInnerText: true });
    await expect(unsaved).toBeHidden();

    await page.locator('input[id="form.subtitle"]').fill('عنوان فرعي جديد');
    await expect(unsaved).toBeVisible();
    await expect(state).not.toContainText('لا تغييرات', { useInnerText: true });

    await afterLivewire(page, () => page.keyboard.press('Control+s'), 'save');
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();
    await expect(unsaved).toBeHidden();
    expect(app.sql(`SELECT status || '|' || subtitle FROM posts WHERE id = ${id}`)).toBe('draft|عنوان فرعي جديد');

    // A change not saved yet: the browser asks before the page goes.
    await page.locator('input[id="form.subtitle"]').fill('تغيير لم يُحفظ');
    await expect(unsaved).toBeVisible();
    const asked = page.waitForEvent('dialog');
    await page.close({ runBeforeUnload: true });
    const dialog = await asked;
    expect(dialog.type()).toBe('beforeunload');
    await dialog.dismiss();
});

/** On a phone the bar has two steady rows: the state, then the buttons, all on one row (and nothing that jumps while typing). */
async function barRows(page: Page) {
    const state = (await page.locator('.raqam-save-state').boundingBox())!;
    const tops = await page.locator('.fi-sc-actions .fi-ac button:visible').evaluateAll((buttons) =>
        buttons.map((b) => Math.round(b.getBoundingClientRect().top)));
    expect(new Set(tops).size, `the bar's buttons in one row: ${tops.join(', ')}`).toBe(1);
    expect(state.y + state.height, 'the state above the buttons').toBeLessThanOrEqual(tops[0] + 1);
}

test.describe('on a phone', () => {
    test.use({ viewport: { width: 360, height: 780 }, isMobile: true, hasTouch: true });

    test('«النشر» comes before the text, and the bar with «نشر» stays on screen while writing', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');
        const id = await createPost(page, 'خبر', 'خبر من شاشة صغيرة');

        const publishing = page.locator('.raqam-post-publish');
        const text = page.locator('.tiptap.ProseMirror').first();
        expect((await publishing.boundingBox())!.y).toBeLessThan((await text.boundingBox())!.y);

        await fillRequired(page, 'نص طويل يكتبه المحرر من هاتفه.');
        await text.scrollIntoViewIfNeeded();
        const publish = page.getByRole('button', { name: 'نشر', exact: true });
        await expect(publish).toBeInViewport();
        expect((await publish.boundingBox())!.height, 'tap target').toBeGreaterThanOrEqual(44);
        await expect(page.locator('.raqam-bar-cancel')).toBeHidden();
        await barRows(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

        await publish.click();
        await expect(page.locator('.fi-modal-window').getByText('نشر «خبر من شاشة صغيرة»')).toBeVisible();
        await confirmDialog(page, 'نشر');
        await expect(page.getByText('تم الحفظ').first()).toBeVisible();
        expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('published');

        // The same page's bar now updates the published item.
        await expect(page.getByRole('button', { name: 'تحديث' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'نشر', exact: true })).toHaveCount(0);
        // «تحديث» and «المزيد» (an icon, with its name for screen readers) stay on the buttons' row.
        await expect(page.getByRole('button', { name: 'المزيد' })).toBeVisible();
        await barRows(page);
        await page.screenshot({ path: test.info().outputPath('bar-published-360.png') });
    });
});

test('a published item goes back to a draft from «المزيد», after a confirmation that names it', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await publishedNews(page, 'خبر يعود مسودة');

    // The state is not a field to change on the page: the bar is the one way.
    await expect(page.locator('.raqam-save-state')).toContainText('منشور');
    await page.getByRole('button', { name: 'المزيد' }).click();
    await page.getByRole('menuitem', { name: 'إلغاء النشر' }).click();
    await expect(page.locator('.fi-modal-window:visible')).toContainText('إلغاء النشر «خبر يعود مسودة»');
    await confirmDialog(page, 'إلغاء النشر');

    expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('draft');
    await expect(page.locator('.raqam-save-state')).toContainText('مسودة');
    await expect(page.getByRole('button', { name: 'نشر', exact: true })).toBeVisible();
});
