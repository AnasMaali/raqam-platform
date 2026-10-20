import { test, expect, panel, signIn } from '../support/fixtures';
import { file } from '../support/files';
import { mp4 } from '../support/clips';
import { afterLivewire, createPost, fillRequired, pick, uploadImage } from '../support/panel';

// The video and awareness screens on a phone (the "mobile" project: a Pixel 7, 412 px wide).

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test.beforeEach(({ youtube }) => {
    youtube.add({ id: 'aaaaaaaaaaa', title: 'حلقة الاقتصاد الأولى', duration: 'PT10M30S' });
    youtube.add({ id: 'bbbbbbbbbbb', title: 'حلقة الاقتصاد الثانية', duration: 'PT5M' });
});

test('every video and awareness screen fits a phone', async ({ page, app, adminPassword }) => {
    app.artisan(['characters:install']);
    await signIn(page, app, adminPassword);

    await page.goto(panel('/videos/create'));
    await page.locator('input[id="form.url"]').fill('https://youtu.be/aaaaaaaaaaa');
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/videos\/\d+\/edit/);
    const video = page.url().match(/videos\/(\d+)\/edit/)![1];

    for (const [name, path] of [
        ['video library', '/videos'], ['add video', '/videos/create'], ['edit video', `/videos/${video}/edit`],
        ['playlists', '/playlists'], ['new playlist', '/playlists/create'],
        ['characters', '/characters'], ['new character', '/characters/create'], ['edit character', '/characters/1/edit'],
    ] as const) {
        await page.goto(panel(path));
        await expect(page.locator('main, .fi-main').first()).toBeVisible();
        await noSideScroll(page, name);
    }
});

test('a video is added by link and put in a playlist from a phone', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    for (const id of ['aaaaaaaaaaa', 'bbbbbbbbbbb']) {
        await page.goto(panel('/videos/create'));
        await page.locator('input[id="form.url"]').fill(`https://youtu.be/${id}`);
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await page.waitForURL(/videos\/\d+\/edit/);
    }
    expect(app.sql('SELECT count(*) FROM videos')).toBe('2');

    await page.goto(panel('/playlists/create'));
    await page.locator('input[id="form.name_ar"]').fill('قائمة الجوال');
    await page.locator('input[id="form.name_ar"]').press('Tab');
    await expect(page.locator('input[id="form.slug"]')).not.toHaveValue('');
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'إضافة فيديو' }).click();
    const key = (await page.locator('[id^="form.videos."][id$=".video_id"]').first().getAttribute('id'))!.replace(/^form\.videos\./, '').replace(/\.video_id$/, '');
    await pick(page, `videos.${key}.video_id`, 'حلقة الاقتصاد الثانية', 'الثانية');
    await noSideScroll(page, 'playlist form with a video');
    const add = page.getByRole('button', { name: 'إضافة', exact: true });
    await add.scrollIntoViewIfNeeded();
    expect((await add.boundingBox())!.height, 'tap target height').toBeGreaterThanOrEqual(32);
    await add.click();
    await page.waitForURL(/playlists\/\d+\/edit/);

    expect(app.sql('SELECT count(*) FROM playlist_videos')).toBe('1');
});

test('a short clip is uploaded from a phone, and a character card is published from it', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    const media = await uploadImage(page, 'cover.png', 'صورة');

    await page.goto(panel('/videos/create'));
    await pick(page, 'source', 'مقطع قصير من الجهاز (MP4)');
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.getByText('تصفح').click()]);
    await chooser.setFiles(file('clip.mp4', 'video/mp4', mp4(3, true)));
    await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible({ timeout: 30_000 });
    await page.locator('input[id="form.upload_title"]').fill('لقطة من الجوال');
    await noSideScroll(page, 'upload form');
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/videos\/\d+\/edit/);
    expect(app.sql("SELECT source FROM videos")).toBe('upload');

    const character = Number(app.sql("INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('المستشار', 'advisor', 'ينصح', '#1F6F5B') RETURNING id"));
    app.sql(`INSERT INTO character_poses (character_id, pose_type_code, static_media_id) VALUES (${character}, 'explaining', ${media})`);

    const card = await createPost(page, 'بطاقة توعية', 'بطاقة من الجوال');
    await fillRequired(page, 'نصيحة من الهاتف.');
    await pick(page, 'awareness.character_id', 'المستشار');
    await pick(page, 'awareness.character_pose_id', 'تشرح');
    await pick(page, 'awareness.awareness_type_code', 'نصيحة');
    await noSideScroll(page, 'card editor');
    const publish = page.getByRole('button', { name: 'نشر الآن' });
    await publish.scrollIntoViewIfNeeded();
    await publish.click();
    await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();

    expect(app.sql(`SELECT status FROM posts WHERE id = ${card}`)).toBe('published');
});
