import { test, expect, panel, signIn } from '../support/fixtures';
import { createPost, fillRequired, uploadImage, afterLivewire } from '../support/panel';

// The same important paths on a phone (the "mobile" project runs these with a Pixel 7: 412 px wide).

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('sign-in on a phone: nothing sticks out, the code page fits, and the panel opens', async ({ page, app, adminPassword }) => {
    await page.goto(panel('/login'));
    await noSideScroll(page, 'sign-in page');
    await signIn(page, app, adminPassword);
    await noSideScroll(page, 'dashboard');
    await expect(page.getByRole('button', { name: /القائمة الجانبية|فتح القائمة/ }).first()).toBeVisible();   // the menu is behind a button, not squeezed in
});

test('every main screen fits a phone without sideways scrolling', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    await createPost(page, 'خبر', 'خبر الجوال');

    for (const [name, path] of [
        ['dashboard', ''], ['posts', '/posts'], ['new post', '/posts/create'], ['media library', '/media-assets'], ['upload', '/media-assets/create'],
        ['categories', '/categories'], ['tags', '/tags'], ['authors', '/authors'], ['guests', '/guests'], ['pages', '/pages'], ['menus', '/menus'],
        ['redirects', '/redirects'], ['settings', '/settings'], ['feature switches', '/feature-flags'], ['audit log', '/audit-logs'], ['password', '/password'],
    ] as const) {
        await page.goto(panel(path));
        await expect(page.locator('main, .fi-main').first()).toBeVisible();
        await noSideScroll(page, name);
    }

    await page.goto(panel('/posts/1/edit'));
    await noSideScroll(page, 'post editor');
});

test('an editor can write and publish an item from a phone', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await createPost(page, 'خبر', 'خبر عاجل من الجوال');
    await fillRequired(page, 'نص مكتوب من الهاتف.');

    // The publishing buttons are reachable (they may wrap, but must be visible and tappable).
    const publish = page.getByRole('button', { name: 'نشر الآن' });
    await publish.scrollIntoViewIfNeeded();
    const box = (await publish.boundingBox())!;
    expect(box.height, 'tap target height').toBeGreaterThanOrEqual(32);
    await publish.click();
    const confirm = page.getByRole('button', { name: 'تأكيد' });
    await expect(confirm).toBeVisible();
    await afterLivewire(page, () => confirm.click());

    expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('published');
    expect((await page.request.get(`${app.url}/api/v1/posts/${id}`)).status()).toBe(200);
    await noSideScroll(page, 'editor after publishing');
});
