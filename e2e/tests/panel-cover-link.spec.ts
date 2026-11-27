import AxeBuilder from '@axe-core/playwright';

import { test, expect, panel, signIn } from '../support/fixtures';
import { createPost, uploadImage } from '../support/panel';

// The posts list's cover picture is a second link to the editor beside the title: it stays a link for the pointer,
// but is out of the tab order and hidden from screen readers, so the title is named and read once (axe "link-name").

for (const width of [1440, 375]) {
    test(`posts list at ${width}px: no link without a name, and the keyboard goes to the title, not the picture`, async ({ page, app, adminPassword }) => {
        await page.setViewportSize({ width, height: 900 });
        await signIn(page, app, adminPassword);
        const picture = await uploadImage(page, 'cover.png', 'صورة');
        const id = await createPost(page, 'خبر', 'خبر بصورة غلاف');
        app.sql(`UPDATE posts SET cover_media_id = ${picture} WHERE id = ${id}`);

        await page.goto(panel('/posts'));
        const cover = page.locator('.raqam-cover-link');
        await expect(cover).toHaveCount(1);
        await expect(cover).toHaveAttribute('tabindex', '-1');
        await expect(cover).toHaveAttribute('aria-hidden', 'true');
        await expect(cover.locator('img')).toHaveAttribute('alt', '');

        const axe = await new AxeBuilder({ page }).include('.fi-ta').withRules(['link-name', 'aria-hidden-focus', 'image-alt']).analyze();
        expect(axe.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target).join(' ')}`)).toEqual([]);

        // From the row's box, Tab reaches the title's link next (the picture is skipped).
        await page.locator('.fi-ta-record-checkbox').first().focus();
        await page.keyboard.press('Tab');
        await expect(page.locator(':focus')).toHaveText(/خبر بصورة غلاف/);

        // The picture still opens the editor for a pointer.
        await cover.click();
        await page.waitForURL(new RegExp(`/posts/${id}/edit`));
    });
}
