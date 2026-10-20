import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, createPost, fillRequired, pick, save, uploadImage } from '../support/panel';

const api = (app: { url: string }) => `${app.url}/api/v1`;

/** The colour field is a picker with a text box: type the code as a person would, then leave the box. */
async function typeColour(page: import('@playwright/test').Page, hex: string) {
    const box = page.locator('input[id="form.accent_color"]');
    await box.click();
    await box.press('Control+a');
    await box.pressSequentially(hex, { delay: 20 });
    // The picker keeps the typed value to itself until it is closed; click the page title to close it.
    await page.getByRole('heading').first().click();
    // The field is live: the contrast shows beside it once the server has the colour.
    await expect(page.getByText(new RegExp('التباين مع خلفية الصفحة: ' + '\\d')).first()).toBeVisible();
}

test.describe('awareness characters and cards', () => {
    test('the eight characters are installed with their first names and can be renamed', async ({ page, app, adminPassword }) => {
        app.artisan(['characters:install']);
        await signIn(page, app, adminPassword);

        await page.goto(panel('/characters'));
        for (const name of ['رَقَم', 'هل تعلم؟', 'انتبه!', 'المستشار', 'المدّخِر', 'حارس الحقوق', 'كاشف الاحتيال', 'رقم اليوم']) {
            await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
        }
        await expect(page.getByText('ناقص:').first()).toBeVisible();   // no pictures yet

        await page.getByText('المستشار', { exact: true }).first().click();
        await page.waitForURL(/characters\/\d+\/edit/);
        await page.locator('input[id="form.name_ar"]').fill('المستشار المالي');
        await save(page);
        expect(app.sql("SELECT count(*) FROM characters WHERE name_ar = 'المستشار المالي'")).toBe('1');

        // Running the installer again changes nothing.
        app.artisan(['characters:install']);
        expect(app.sql('SELECT count(*) FROM characters')).toBe('8');
        expect(app.sql("SELECT count(*) FROM characters WHERE name_ar = 'المستشار'")).toBe('0');
    });

    test('a character is created with a readable colour and its poses; a pale colour is refused', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'explain.png', 'يشرح');
        await uploadImage(page, 'warn.png', 'يحذّر', [1200, 801]);

        await page.goto(panel('/characters/create'));
        await page.locator('input[id="form.name_ar"]').fill('الخبير');
        await page.locator('input[id="form.name_ar"]').press('Tab');   // the name sets the address, on the server
        await expect(page.locator('input[id="form.slug"]')).not.toHaveValue('');
        await page.waitForLoadState('networkidle');
        await page.locator('input[id="form.role_ar"]').fill('يشرح الأرقام ببساطة');
        await typeColour(page, '#FFFF00');
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await expect(page.locator('.fi-fo-field-wrp-error-message, [data-validation-error]').first()).toContainText('التباين');
        expect(app.sql('SELECT count(*) FROM characters')).toBe('0');

        await typeColour(page, '#0B5FA5');
        await pick(page, 'poses.explaining.static_media_id', 'explain.png', 'explain');
        await pick(page, 'poses.warning.static_media_id', 'warn.png', 'warn');
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await page.waitForURL(/characters\/\d+\/edit/);

        expect(app.sql("SELECT accent_color, role_ar FROM characters WHERE name_ar = 'الخبير'")).toBe('#0B5FA5|يشرح الأرقام ببساطة');
        expect(app.sql("SELECT string_agg(pose_type_code, ',' ORDER BY pose_type_code) FROM character_poses")).toBe('explaining,warning');

        const character = await (await page.request.get(`${api(app)}/characters/${app.sql("SELECT slug FROM characters WHERE name_ar = 'الخبير'")}`)).json();
        expect(character.data.poses.map((p: { code: string }) => p.code)).toEqual(['explaining', 'warning']);
        expect(character.data.accent_color).toBe('#0B5FA5');
    });

    test('a card is made with a character and a pose, published, shown on the character page and linked to a news item', async ({ page, app, adminPassword }) => {
        test.setTimeout(180_000);   // two items made and published through the editor
        await signIn(page, app, adminPassword);
        const media = await uploadImage(page, 'cover.png', 'صورة');
        const character = Number(app.sql("INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('المستشار', 'advisor', 'ينصح', '#1F6F5B') RETURNING id"));
        app.sql(`INSERT INTO character_poses (character_id, pose_type_code, static_media_id) VALUES (${character}, 'explaining', ${media}), (${character}, 'warning', ${media})`);

        // A card cannot be published with the character but no pose.
        const card = await createPost(page, 'بطاقة توعية', 'ادّخر قبل أن تنفق');
        await fillRequired(page, 'نصيحة اليوم: ادّخر جزءاً من دخلك.');
        await pick(page, 'awareness.character_id', 'المستشار');
        await pick(page, 'awareness.awareness_type_code', 'نصيحة');
        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
        expect(app.sql(`SELECT status FROM posts WHERE id = ${card}`)).toBe('draft');

        await pick(page, 'awareness.character_pose_id', 'تشرح');
        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
        await expect(page.getByText('تم الحفظ').first()).toBeVisible();
        expect(app.sql(`SELECT status FROM posts WHERE id = ${card}`)).toBe('published');

        const cards = await (await page.request.get(`${api(app)}/characters/advisor/cards`)).json();
        expect(cards.data.map((c: { id: number }) => c.id)).toEqual([card]);
        expect(cards.data[0].awareness.pose.code).toBe('explaining');

        // A news item with the card picked for it.
        const news = await createPost(page, 'خبر', 'خبر يرافقه بطاقة');
        await fillRequired(page, 'نص الخبر.');
        await page.getByText('بطاقات توعية مرتبطة').click();
        await pick(page, 'awareness_cards', 'ادّخر قبل أن تنفق', 'ادّخر');
        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
        await expect(page.getByText('تم الحفظ').first()).toBeVisible();

        const detail = await (await page.request.get(`${api(app)}/posts/${news}`)).json();
        expect(detail.data.awareness_cards.map((c: { id: number }) => c.id)).toEqual([card]);
        expect(detail.data.awareness_cards[0].awareness.character.slug).toBe('advisor');

        const page_ = await (await page.request.get(`${api(app)}/awareness`)).json();
        expect(page_.data.latest.map((c: { id: number }) => c.id)).toEqual([card]);
    });
});

test.describe('video on an item', () => {
    test('a video is chosen in the editor and the API gives the player data, with our picture and no YouTube address for the page', async ({ page, app, adminPassword, youtube }) => {
        youtube.add({ id: 'aaaaaaaaaaa', title: 'حلقة الاقتصاد', duration: 'PT10M' });
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');
        await page.goto(panel('/videos/create'));
        await page.locator('input[id="form.url"]').fill('https://youtu.be/aaaaaaaaaaa');
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await page.waitForURL(/videos\/\d+\/edit/);
        const video = Number(page.url().match(/videos\/(\d+)\/edit/)![1]);

        const id = await createPost(page, 'خبر', 'خبر مع فيديو');
        await fillRequired(page, 'نص الخبر.');
        await pick(page, 'primary_video_id', 'حلقة الاقتصاد', 'حلقة');
        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
        await expect(page.getByText('تم الحفظ').first()).toBeVisible();

        expect(app.sql(`SELECT primary_video_id FROM posts WHERE id = ${id}`)).toBe(String(video));

        const { data } = await (await page.request.get(`${api(app)}/posts/${id}`)).json();
        expect(data.video.youtube.embed_url).toBe('https://www.youtube-nocookie.com/embed/aaaaaaaaaaa');
        expect(data.video.thumbnail_url).toContain('/storage/media/');   // our copy
        expect(JSON.stringify({ ...data, video: { ...data.video, structured_data: undefined } })).not.toContain('ytimg');

        // The video library shows it now that an item uses it; the video's own usage list names the item.
        const library = await (await page.request.get(`${api(app)}/videos`)).json();
        expect(library.data.map((v: { id: number }) => v.id)).toEqual([video]);
        await page.goto(panel(`/videos/${video}/edit`));
        await expect(page.getByText('خبر مع فيديو — فيديو رئيسي')).toBeVisible();
    });
});

const api_ = api;
void api_;
