import { test, expect, panel, signIn } from '../support/fixtures';
import { pick, afterLivewire, save } from '../support/panel';

const A = 'aaaaaaaaaaa';
const B = 'bbbbbbbbbbb';
const C = 'ccccccccccc';

test.beforeEach(({ youtube }) => {
    youtube.add({ id: A, title: 'حلقة الاقتصاد الأولى', description: 'وصف الحلقة الأولى', duration: 'PT10M30S' });
    youtube.add({ id: B, title: 'حلقة الاقتصاد الثانية', duration: 'PT5M' });
    youtube.add({ id: C, title: 'مقطع قصير', duration: 'PT45S' });
});

async function addByLink(page: import('@playwright/test').Page, link: string) {
    await page.goto(panel('/videos/create'));
    await page.locator('input[id="form.url"]').fill(link);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/videos\/\d+\/edit/);
    return Number(page.url().match(/videos\/(\d+)\/edit/)![1]);
}

test.describe('video library', () => {
    test('a video is added from the three kinds of link, once each, with its facts and its picture from our copy', async ({ page, app, adminPassword, youtube }) => {
        await signIn(page, app, adminPassword);

        const a = await addByLink(page, `https://www.youtube.com/watch?v=${A}`);
        const b = await addByLink(page, `https://youtu.be/${B}?si=track`);
        const c = await addByLink(page, `https://www.youtube.com/shorts/${C}`);
        expect(new Set([a, b, c]).size).toBe(3);

        expect(app.sql(`SELECT display_title IS NULL, duration_seconds FROM videos WHERE id = ${a}`)).toBe('t|630');
        expect(app.sql(`SELECT source_title, source_description, availability FROM youtube_videos WHERE video_id = ${a}`)).toBe('حلقة الاقتصاد الأولى|وصف الحلقة الأولى|ok');
        expect(app.sql(`SELECT is_short FROM youtube_videos WHERE video_id = ${c}`)).toBe('t');
        expect(app.sql(`SELECT is_short FROM youtube_videos WHERE video_id = ${a}`)).toBe('f');

        // The picture is ours: stored in the media library and used as the cover.
        for (const id of [a, b, c]) {
            expect(app.sql(`SELECT m.mime_type FROM videos v JOIN media_assets m ON m.id = v.poster_media_id WHERE v.id = ${id}`), `video ${id}`).toMatch(/^image\//);
        }

        // The same video pasted again, in another shape, is the same video.
        const again = await addByLink(page, `https://www.youtube.com/shorts/${A}`);
        expect(again).toBe(a);
        expect(app.sql('SELECT count(*) FROM videos')).toBe('3');

        // The key went to the API in a header, and only there.
        expect(youtube.requests.filter((r) => r.path.startsWith('/api/')).every((r) => r.key === 'e2e-fake-youtube-key')).toBe(true);
        expect(youtube.requests.some((r) => r.path.includes('e2e-fake-youtube-key'))).toBe(false);
        expect(app.dump()).not.toContain('e2e-fake-youtube-key');
        expect(app.log()).not.toContain('e2e-fake-youtube-key');
    });

    test('a link that is not YouTube is refused with a sentence, and nothing is added', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        await page.goto(panel('/videos/create'));
        await page.locator('input[id="form.url"]').fill('https://vimeo.com/123456');
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();

        const refusal = page.locator('.fi-fo-field-wrp-error-message, [data-validation-error]').first();
        await expect(refusal).toContainText('ليس رابط فيديو يوتيوب');
        expect(app.sql('SELECT count(*) FROM videos')).toBe('0');
    });

    test('the title and description shown on the site change without asking YouTube', async ({ page, app, adminPassword, youtube }) => {
        await signIn(page, app, adminPassword);
        const id = await addByLink(page, `https://youtu.be/${A}`);
        const asked = youtube.requests.length;

        await page.locator('input[id="form.display_title"]').fill('عنوان للموقع');
        await page.locator('textarea[id="form.display_description"]').fill('وصف للموقع');
        await save(page);

        expect(app.sql(`SELECT display_title, display_description FROM videos WHERE id = ${id}`)).toBe('عنوان للموقع|وصف للموقع');
        expect(app.sql(`SELECT source_title FROM youtube_videos WHERE video_id = ${id}`)).toBe('حلقة الاقتصاد الأولى');
        expect(youtube.requests.length).toBe(asked);   // saving never calls YouTube
    });

    test('chapters: a time past the end of the video is refused, a good one is saved and comes back', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const id = await addByLink(page, `https://youtu.be/${A}`);   // 10:30 long

        await page.getByText('المحاور (أوقات داخل الفيديو)').click();
        await page.getByRole('button', { name: 'إضافة محور' }).click();
        await page.locator('input[id$=".time"]').first().fill('11:00');
        await page.locator('input[id$=".title"]').first().fill('بعد النهاية');
        await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
        await expect(page.getByText('يتجاوز مدة الفيديو').first()).toBeVisible();
        expect(app.sql(`SELECT count(*) FROM video_chapters WHERE video_id = ${id}`)).toBe('0');

        await page.locator('input[id$=".time"]').first().fill('2:30');
        await page.locator('input[id$=".title"]').first().fill('الموضوع الرئيسي');
        await save(page);
        expect(app.sql(`SELECT starts_at_seconds, title FROM video_chapters WHERE video_id = ${id}`)).toBe('150|الموضوع الرئيسي');

        await page.reload();
        await page.getByText('المحاور (أوقات داخل الفيديو)').click();
        await expect(page.locator('input[id$=".time"]').first()).toHaveValue('2:30');
    });

    test('a playlist is made and its videos are re-ordered by dragging', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const a = await addByLink(page, `https://youtu.be/${A}`);
        const b = await addByLink(page, `https://youtu.be/${B}`);
        const c = await addByLink(page, `https://youtu.be/${C}`);

        await page.goto(panel('/playlists/create'));
        await page.locator('input[id="form.name_ar"]').fill('الموسم الأول');
        for (const [i, title] of ['حلقة الاقتصاد الأولى', 'حلقة الاقتصاد الثانية', 'مقطع قصير'].entries()) {
            await page.getByRole('button', { name: 'إضافة فيديو' }).click();
            await pick(page, `videos.${await nthItemKey(page, i)}.video_id`, title, title.slice(0, 6));
        }
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await page.waitForURL(/playlists\/\d+\/edit/);
        const playlist = Number(page.url().match(/playlists\/(\d+)\/edit/)![1]);
        expect(app.sql(`SELECT string_agg(video_id::text, ',' ORDER BY sort_order) FROM playlist_videos WHERE playlist_id = ${playlist}`)).toBe(`${a},${b},${c}`);

        // Drag the last one to the top. A drag that opens a place one item too low (a slow machine) is simply done again
        // from where it landed, as an editor would; what matters is that the order on screen is the order saved.
        const items = page.locator('.fi-fo-repeater-item');
        await expect(items).toHaveCount(3);
        const first = () => items.nth(0).innerText();
        for (let attempt = 0; attempt < 3 && !(await first()).includes('مقطع قصير'); attempt++) {
            const moving = items.filter({ hasText: 'مقطع قصير' });
            const handle = moving.locator('[x-sortable-handle]').first();
            await handle.hover();
            const from = (await handle.boundingBox())!;
            const to = (await items.nth(0).boundingBox())!;
            await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
            await page.mouse.down();
            // Past the top edge of the first item, in small steps, so the list has time to open a place there.
            await page.mouse.move(from.x + from.width / 2, from.y - 10, { steps: 5 });
            await page.mouse.move(from.x + from.width / 2, (from.y + to.y) / 2, { steps: 20 });
            await page.mouse.move(from.x + from.width / 2, to.y - 12, { steps: 20 });
            await page.mouse.move(from.x + from.width / 2, to.y - 8, { steps: 5 });
            await page.waitForTimeout(300);
            await page.mouse.up();
            await page.waitForTimeout(300);
        }
        expect(await first(), 'the dragged video is on top').toContain('مقطع قصير');
        await save(page);

        expect(app.sql(`SELECT string_agg(video_id::text, ',' ORDER BY sort_order) FROM playlist_videos WHERE playlist_id = ${playlist}`)).toBe(`${c},${a},${b}`);
    });
});

/** The key Filament gave the n-th item of the playlist's repeater (it is a random id, read from the page). */
async function nthItemKey(page: import('@playwright/test').Page, n: number): Promise<string> {
    const id = await page.locator('.fi-fo-repeater-item').nth(n).locator('[id^="form.videos."][id$=".video_id"]').first().getAttribute('id');
    return id!.replace(/^form\.videos\./, '').replace(/\.video_id$/, '');
}
