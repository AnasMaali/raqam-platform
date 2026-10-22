import { test, expect, panel, signIn } from '../support/fixtures';
import { campaign } from '../support/ads';

// Phase 7 screens on a phone (Pixel 7, 412 px): nothing sticks out sideways.

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('campaigns, the campaign form and report, slots, advertisers and services fit a phone', async ({ page, app }) => {
    const id = campaign(app, 'حملة الجوال', 50);
    app.sql(`INSERT INTO ad_stats_daily VALUES (${id}, 'header_leaderboard', (now() AT TIME ZONE 'Asia/Hebron')::date, 1234, 56)`);
    app.artisan(['services:install']);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);

    for (const [path, text, where] of [
        ['/ad-campaigns', 'حملة الجوال', 'campaigns'],
        ['/ad-campaigns/create', 'رابط المعلن', 'campaign form'],
        [`/ad-campaigns/${id}/report`, '4.54%', 'campaign report'],
        ['/ad-placements', 'بانر أعلى الصفحة', 'slots'],
        ['/advertisers', 'معلن حملة الجوال', 'advertisers'],
        ['/services', 'حلقة بودكاست', 'services'],
        ['/services/create', 'طريقة العرض', 'service form'],
    ] as const) {
        await page.goto(panel(path));
        await expect(page.getByText(text).first()).toBeVisible();
        await noSideScroll(page, where);
    }

    await page.goto(panel('/ad-placements'));
    await page.locator('tr').filter({ hasText: 'مربع جانبي' }).first().getByRole('button', { name: 'تعديل' }).click();
    await expect(page.locator('.fi-modal-window:visible').last().getByText('المقاس المحجوز')).toBeVisible();
    await noSideScroll(page, 'slot dialog');
});
