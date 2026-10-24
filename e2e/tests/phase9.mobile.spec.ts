import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire } from '../support/panel';
import { hit, livePost } from '../support/stats';

// Phase 9 screens on a phone (Pixel 7, 412 px): nothing sticks out sideways, the chart fits, and the home page is
// ordered with the arrows.

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('statistics, missing pages, the audit trail and the sign-in attempts fit a phone', async ({ page, app, adminPassword }) => {
    test.setTimeout(180_000);
    const post = livePost(app, 'خبر بعنوان طويل جداً عن أسعار الذهب والدولار والدينار في أسواق نابلس اليوم');
    await hit(page, app, { type: 'view', path: `/news/${post}/x`, post_id: post, referrer: 'https://www.google.com/' });
    await hit(page, app, { type: 'not_found', path: '/news/12/عنوان-قديم-طويل-جداً-لخبر-لم-يعد-موجوداً-في-الموقع-منذ-زمن-بعيد' });
    await signIn(page, app, adminPassword);
    const entry = app.sql('SELECT max(id) FROM audit_log');

    for (const [path, text, where] of [
        ['/statistics', 'المشاهدات والزوار يوماً بيوم', 'statistics'],
        ['/not-found-paths', '/news/12/عنوان-قديم', 'missing pages'],
        ['/audit-logs', 'مدير الاختبار', 'audit log'],
        [`/audit-logs/${entry}`, 'التغييرات: قبل وبعد', 'an audit entry'],
        ['/login-attempts', 'e2e-admin@example.test', 'sign-in attempts'],
        ['/home-layout', 'ترتيب مقترح لم يُحفظ بعد', 'home layout'],
    ] as const) {
        await page.goto(panel(path));
        // Hidden copies (a filter's options) come first in the page; the one on screen is what counts.
        await expect(page.locator('.fi-main').getByText(text).locator('visible=true').first()).toBeVisible();
        await noSideScroll(page, where);
    }

    await page.goto(panel('/statistics'));
    const chart = page.locator('[data-statistics] svg').first();
    const box = (await chart.boundingBox())!;
    expect(box.width).toBeLessThanOrEqual(412);
    expect(box.width).toBeGreaterThan(200);
});

test('on a phone the home page is ordered with the arrows and saved', async ({ page, app, adminPassword }) => {
    app.sql(`INSERT INTO home_blocks (block_type, sort_order) VALUES ('hero', 1), ('breaking', 2), ('latest', 3)`);
    await signIn(page, app, adminPassword);
    await page.goto(panel('/home-layout'));

    const items = page.locator('.fi-fo-repeater-item');
    const block = (title: string) => items.filter({ has: page.locator('.fi-fo-repeater-item-header-label', { hasText: new RegExp(`^\\s*${title}\\s*$`) }) });
    const place = async () => (await items.locator('.fi-fo-repeater-item-header-label').allInnerTexts()).findIndex((t) => t.trim() === 'آخر الأخبار');
    for (let at = await place(); at > 0; at = await place()) {
        await afterLivewire(page, () => block('آخر الأخبار').getByRole('button', { name: 'تحريك لأعلى' }).click());
        await expect.poll(place).toBe(at - 1);
    }
    await noSideScroll(page, 'home layout after moving');
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ الترتيب' }).click(), 'save');
    await expect(page.getByText('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.').first()).toBeVisible();

    expect(app.sql(`SELECT string_agg(block_type, ',' ORDER BY sort_order) FROM home_blocks`)).toBe('latest,hero,breaking');
});
