import { test, expect, signIn } from '../support/fixtures';
import { createPost, pick, save, uploadImage, writeBody } from '../support/panel';
import { seed } from '../support/seed';

type Kind = {
    code: string;
    label: string;
    /** The first part of the item's public address (decision 23.3). */
    prefix: string;
    /** Rows the picker of this kind chooses from; inserted before the editor opens (a reload would lose the typing). */
    seed?: (app: import('../support/app').App, mediaId: number) => void;
    /** Fills the facts of this kind, which only its own section shows. */
    facts?: (page: import('@playwright/test').Page) => Promise<void>;
};

const kinds: Kind[] = [
    { code: 'news', label: 'خبر', prefix: '/news/' },
    { code: 'report', label: 'تقرير', prefix: '/news/' },
    { code: 'opinion', label: 'مقال رأي', prefix: '/news/' },
    {
        code: 'infographic', label: 'إنفوغراف', prefix: '/news/',
        facts: async (page) => pick(page, 'infographic.download_media_id', 'cover.png', 'cover'),
    },
    {
        code: 'episode', label: 'حلقة بودكاست', prefix: '/podcast/',
        seed: (app) => void seed.season(app),
        facts: async (page) => {
            await pick(page, 'episode.season_id', 'الموسم 1');
            await page.locator('input[id="form.episode.episode_number"]').fill('7');
        },
    },
    {
        code: 'story', label: 'قصة نجاح', prefix: '/success-stories/',
        seed: (app) => void seed.organization(app, 'مصنع النجاح'),
        facts: async (page) => {
            const name = 'مصنع النجاح';
            await pick(page, 'story.organization_id', name, 'النجاح');
            await page.locator('input[id="form.story.person_name"]').fill('أحمد المصري');
        },
    },
    {
        code: 'product', label: 'منتج', prefix: '/made-in-nablus/',
        seed: (app) => void seed.organization(app, 'مصنع الصابون'),
        facts: async (page) => {
            const name = 'مصنع الصابون';
            await page.locator('input[id="form.product.product_name"]').fill('صابون نابلسي');
            await pick(page, 'product.organization_id', name, 'الصابون');
            await page.locator('input[id="form.product.contact_phone"]').fill('+970599123456');
        },
    },
    {
        code: 'history', label: 'تاريخ', prefix: '/history/',
        seed: (app) => void seed.era(app),
        facts: async (page) => {
            await pick(page, 'history.era_id', 'العهد العثماني');
            await page.locator('input[id="form.history.period_label"]').fill('أواخر القرن التاسع عشر');
        },
    },
    {
        code: 'initiative', label: 'مبادرة', prefix: '/initiatives/',
        seed: (app) => void seed.organization(app, 'غرفة تجارة نابلس'),
        facts: async (page) => {
            const name = 'غرفة تجارة نابلس';
            await page.locator('input[id="form.initiative.initiative_name"]').fill('مبادرة دعم المشاريع الصغيرة');
            await pick(page, 'initiative.organizer_organization_id', name, 'غرفة');
            await pick(page, 'initiative.status', 'جارية');
        },
    },
    {
        code: 'roundtable', label: 'جلسة طاولة مستديرة', prefix: '/round-table/',
        facts: async (page) => {
            await page.locator('input[id="form.roundtable.held_on"]').click();
            await page.locator('[id="form.roundtable.held_on-panel"]').getByText('15', { exact: true }).click();
        },
    },
    {
        code: 'awareness', label: 'بطاقة توعية', prefix: '/awareness/',
        seed: (app, mediaId) => void seed.characterPose(app, mediaId),
        facts: async (page) => {
            await pick(page, 'awareness.character_pose_id', 'هل تعلم؟');
            await pick(page, 'awareness.awareness_type_code', 'هل تعلم');
        },
    },
];

for (const kind of kinds) {
    test(`a ${kind.label} is created in the editor and published`, async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const mediaId = await uploadImage(page, 'cover.png', 'صورة رئيسية');

        kind.seed?.(app, mediaId);
        const title = `${kind.label} للاختبار الشامل`;
        const id = await createPost(page, kind.label, title);

        await page.locator('textarea[id="form.excerpt"]').fill(`ملخص ${kind.label}`);
        await writeBody(page, `هذا نص ${kind.label} الكامل المكتوب في المحرر.`);
        if (['news', 'report', 'opinion', 'infographic'].includes(kind.code)) {
            await pick(page, 'category_id', 'محلي (نابلس)');
        }
        await pick(page, 'cover_media_id', 'cover.png', 'cover');
        await kind.facts?.(page);

        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await page.getByRole('button', { name: 'تأكيد' }).click();
        await expect(page.getByText('تم الحفظ')).toBeVisible();

        expect(app.sql(`SELECT status FROM posts WHERE id = ${id}`)).toBe('published');

        // The public API says what the website will show: the kind, and an address under the right section.
        const api = await page.request.get(`${app.url}/api/v1/posts/${id}`);
        expect(api.status()).toBe(200);
        const { data } = await api.json();
        expect(data.kind.code).toBe(kind.code);
        expect(data.title).toBe(title);
        expect(data.path.startsWith(`${kind.prefix}${id}/`), data.path).toBe(true);

        const list = await (await page.request.get(`${app.url}/api/v1/posts?kind=${kind.code}`)).json();
        expect(list.data.map((p: { id: number }) => p.id)).toContain(id);
    });
}
