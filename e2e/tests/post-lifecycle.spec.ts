import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, fillRequired, pick, publishedNews, save, uploadImage, createPost } from '../support/panel';

test.beforeEach(async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة رئيسية');
});

const api = (app: { url: string }, path: string) => `${app.url}/api/v1${path}`;

test('breaking: one button, a time limit, and it shows in the breaking list', async ({ page, app }) => {
    const id = await publishedNews(page, 'خبر عاجل للاختبار');

    await page.getByRole('button', { name: 'عاجل', exact: true }).click();
    const confirm = page.getByRole('button', { name: 'جعله عاجلاً' });
    await expect(confirm).toBeVisible();
    await afterLivewire(page, () => confirm.click());

    // About three hours ahead (the default of the dialog).
    const minutes = Number(app.sql(`SELECT round(extract(epoch FROM breaking_until - now()) / 60) FROM posts WHERE id = ${id}`));
    expect(minutes, 'breaking_until minutes ahead').toBeGreaterThan(170);
    expect(minutes).toBeLessThanOrEqual(181);

    const breaking = await (await page.request.get(api(app, '/posts?breaking=1'))).json();
    expect(breaking.data.map((p: { id: number }) => p.id)).toContain(id);
});

test('revisions: an edit is kept, and an older text comes back (and can be undone)', async ({ page, app }) => {
    const id = await publishedNews(page, 'خبر النسخ', 'النص الأول الأصلي.');

    // Second version: replace the body and save.
    const editor = page.locator('.tiptap.ProseMirror').first();
    await editor.click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('النص الثاني بعد التعديل.');
    await save(page);
    expect(Number(app.sql(`SELECT count(*) FROM post_revisions WHERE post_id = ${id}`))).toBeGreaterThanOrEqual(2);

    // Open the revisions, take the first one back.
    await page.getByRole('button', { name: 'النسخ السابقة' }).click();
    const dialog = page.getByRole('dialog');
    await expect(page.getByRole('button', { name: 'استرجاع هذه النسخة' })).toBeVisible();
    const oldest = app.sql(`SELECT min(revision_no) FROM post_revisions WHERE post_id = ${id} AND body LIKE '%النص الأول الأصلي.%'`);
    await dialog.locator('[id="mountedActionSchema0.revision_no"], [id$="revision_no"]').first().click();
    await dialog.getByRole('option', { name: new RegExp(`^#${oldest} `) }).click();
    await afterLivewire(page, () => dialog.getByRole('button', { name: 'استرجاع هذه النسخة' }).click());
    await expect(page.getByText('استُرجعت النسخة.')).toBeVisible();

    expect(app.sql(`SELECT body FROM posts WHERE id = ${id}`)).toContain('النص الأول الأصلي.');

    // The text that was replaced was kept as a revision first, so the restore itself can be undone.
    expect(app.sql(`SELECT count(*) FROM post_revisions WHERE post_id = ${id} AND body LIKE '%النص الثاني بعد التعديل.%'`)).not.toBe('0');
});

test('trash: it leaves the site at once (410) and comes back when restored', async ({ page, app }) => {
    const id = await publishedNews(page, 'خبر السلة');
    expect((await page.request.get(api(app, `/posts/${id}`))).status()).toBe(200);

    await page.getByRole('button', { name: 'نقل إلى السلة' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'تأكيد' }).click();
    await page.waitForURL(/\/posts\/?(\?.*)?$/);
    expect((await page.request.get(api(app, `/posts/${id}`))).status()).toBe(410);

    // Restore it from the list of trashed items.
    await page.goto(panel('/posts'));
    await page.locator('.fi-tabs-item', { hasText: 'السلة' }).click();
    await page.getByRole('row', { name: /خبر السلة/ }).getByRole('button', { name: 'استرجاع' }).click();
    await expect(page.getByText(/استُرجعت|تم الاسترجاع|تمت الاستعادة/)).toBeVisible();
    expect((await page.request.get(api(app, `/posts/${id}`))).status()).toBe(200);
});

test('slug change: the old address answers 301 to the new one', async ({ page, app }) => {
    const id = await publishedNews(page, 'عنوان قديم للخبر');
    const oldSlug = app.sql(`SELECT slug FROM posts WHERE id = ${id}`);

    const slug = page.locator('input[id="form.slug"]');
    await slug.fill('عنوان-جديد-للخبر');
    await afterLivewire(page, () => slug.blur());   // leaving the field sends its own request
    await save(page);

    const moved = await page.request.get(api(app, `/posts/${id}/${encodeURIComponent(oldSlug)}`), { maxRedirects: 0 });
    expect(moved.status()).toBe(301);
    expect(decodeURIComponent(moved.headers()['location'])).toContain('عنوان-جديد-للخبر');
    expect((await moved.json()).error?.code ?? 'moved').toBeDefined();

    const current = await page.request.get(api(app, `/posts/${id}/${encodeURIComponent('عنوان-جديد-للخبر')}`));
    expect(current.status()).toBe(200);
});

test('scheduling: it stays hidden until its time, then the publisher puts it live', async ({ page, app }) => {
    test.setTimeout(180_000);
    const id = await createPost(page, 'خبر', 'خبر مجدول للاختبار');
    await fillRequired(page);

    await pick(page, 'status', 'مجدول');
    // The editor says the time is Palestine time: a little under two minutes from now, there.
    const target = await page.evaluate(() => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Hebron', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date(Date.now() + 100_000));
        return Object.fromEntries(parts.map((x) => [x.type, x.value]));
    });
    const when = `${target.year}-${target.month}-${target.day} ${target.hour}:${target.minute}`;
    await page.locator('input[id="form.published_at"]').click();
    const panel = page.locator('[id="form.published_at-panel"]');
    await panel.locator(`[data-date="${target.year}-${target.month}-${target.day}"]`).click();
    await panel.getByLabel('الساعة').fill(String(Number(target.hour)));
    await panel.getByLabel('الدقيقة').fill(String(Number(target.minute)));
    await panel.getByLabel('الدقيقة').blur();
    await page.waitForTimeout(800);   // the time fields apply after a short pause
    await page.keyboard.press('Escape');
    await save(page);

    expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('scheduled');
    expect((await page.request.get(api(app, `/posts/${id}`))).status()).toBe(404);   // not public yet

    // The stored time is the one that was typed, read as Palestine time.
    const stored = app.sql(`SELECT to_char(published_at AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD HH24:MI') FROM posts WHERE id = ${id}`);
    expect(stored).toBe(when);

    // Nothing happens before the time ...
    app.artisan(['posts:publish-due']);
    expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('scheduled');

    // ... and once it has passed, the every-minute command publishes it.
    await expect.poll(() => {
        app.artisan(['posts:publish-due']);
        return app.sql(`SELECT status FROM posts WHERE id = ${id}`);
    }, { timeout: 150_000, intervals: [5_000] }).toBe('published');
    expect((await page.request.get(api(app, `/posts/${id}`))).status()).toBe(200);
});
