import type { Page } from '@playwright/test';
import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, pick } from '../support/panel';
import { PERSON_AGENT, SITE, advertiser, api, campaign, dropCaches, hebronParts, picture, setDateTime } from '../support/ads';

// Phase 7: direct ads in a real browser, as a person (not a robot).
test.use({ userAgent: PERSON_AGENT });

const slot = (body: any, code: string) => body.data.find((s: any) => s.code === code);
const row = (page: Page, text: string) => page.locator('tr').filter({ hasText: text }).first();
const dialog = (page: Page) => page.locator('.fi-modal-window:visible').last();

test('a campaign made on the screen shows from its start, rotates by priority, and is gone at its end, by itself', async ({ page, app }) => {
    test.setTimeout(480_000);
    advertiser(app, 'بنك الاختبار');
    picture(app, 'header-desktop.jpg', 970, 90);
    picture(app, 'header-mobile.jpg', 320, 100);
    const other = campaign(app, 'حملة دائمة', 20);
    dropCaches(app);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);

    // Starts two to three minutes from now, ends two minutes after that (the picker has no seconds).
    const start = await hebronParts(page, 180_000);
    const end = await hebronParts(page, 300_000);
    await page.goto(panel('/ad-campaigns/create'));
    await page.locator('[id="form.name"]').fill('حملة الخريف');
    await pick(page, 'advertiser_id', 'بنك الاختبار', 'بنك');
    await page.locator('[id="form.target_url"]').fill('https://bank.example.ps/autumn');
    await page.getByLabel('فعّالة').check();
    await page.locator('[id="form.priority"]').fill('80');
    await setDateTime(page, 'form.starts_at', start);
    await setDateTime(page, 'form.ends_at', end);
    await page.getByLabel('بانر أعلى الصفحة').check();
    await pick(page, 'desktop_media_id', 'header-desktop.jpg', 'header-desktop');
    await pick(page, 'mobile_media_id', 'header-mobile.jpg', 'header-mobile');
    await afterLivewire(page, () => page.locator('button[type="submit"]').filter({ hasText: 'إضافة' }).click(), 'create');
    await page.waitForURL(/ad-campaigns$/);
    await expect(row(page, 'حملة الخريف')).toContainText('مجدولة');
    expect(app.sql(`SELECT to_char(starts_at AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD HH24:MI') FROM ad_campaigns WHERE name = 'حملة الخريف'`))
        .toBe(`${start.year}-${start.month}-${start.day} ${start.hour}:${start.minute}`);

    // Before its start: only the other one.
    expect(slot(await api(page, app, '/ads'), 'header_leaderboard').ads.map((a: any) => a.id)).toEqual([other]);

    // At its start it appears, first (higher priority), with its weight.
    await expect.poll(async () => slot(await api(page, app, '/ads'), 'header_leaderboard').ads.map((a: any) => [a.advertiser, a.weight]),
        { timeout: 200_000, intervals: [5_000] }).toEqual([['بنك الاختبار', 80], ['معلن حملة دائمة', 20]]);

    // The documented weighted pick shows the 80 about four times as often as the 20.
    const shares = await page.evaluate((list: any[]) => {
        const pickAd = (items: any[]) => { let r = Math.random() * items.reduce((s, a) => s + a.weight, 0); for (const a of items) { if ((r -= a.weight) < 0) return a; } return null; };
        let first = 0;
        for (let i = 0; i < 5000; i++) if (pickAd(list)!.weight === 80) first++;
        return first / 5000;
    }, slot(await api(page, app, '/ads'), 'header_leaderboard').ads);
    expect(shares).toBeGreaterThan(0.75);
    expect(shares).toBeLessThan(0.85);

    // At its end it is gone, with nobody touching anything.
    await expect.poll(async () => slot(await api(page, app, '/ads'), 'header_leaderboard').ads.map((a: any) => a.id), { timeout: 200_000, intervals: [5_000] }).toEqual([other]);
    await page.reload();
    await expect(row(page, 'حملة الخريف')).toContainText('منتهية');
});

test('a view and a click are counted, the click lands on the stored address only, and the report exports to Excel and PDF', async ({ page, app }) => {
    const id = campaign(app, 'حملة العدّ', 50);
    dropCaches(app);
    const ad = slot(await api(page, app, '/ads'), 'header_leaderboard').ads[0];
    expect(ad.id).toBe(id);
    expect(ad.rel).toBe('sponsored noopener');

    // The site reports the view (half the ad on screen) with the visitor's address and browser; the same view again does not count.
    const view = (viewId: string) => page.request.post(`${app.url}/api/v1/ads/impressions`, {
        headers: { ...SITE, 'X-Raqam-Visitor-Ip': '203.0.113.50', 'X-Raqam-Visitor-Ua': PERSON_AGENT }, data: { view_id: viewId, items: [{ campaign_id: id, placement: 'header_leaderboard' }] },
    });
    expect((await view('page-view-0001')).status()).toBe(204);
    expect((await view('page-view-0001')).status()).toBe(204);
    expect(app.sql(`SELECT impressions FROM ad_stats_daily WHERE campaign_id = ${id}`)).toBe('1');

    // The click link (as the browser asks it): counted, then 302 to the advertiser's stored address; the link's own ?url= is ignored.
    // (Playwright cannot stand in for the advertiser's site after a redirect, so the redirect itself is checked.)
    const click = await page.request.get(`${ad.click_url}?url=https://evil.example/&next=//evil.example`, { maxRedirects: 0, headers: { 'User-Agent': PERSON_AGENT } });
    expect(click.status()).toBe(302);
    expect(click.headers()['location']).toBe('https://bank.example.ps/offer');
    expect(click.headers()['cache-control']).toContain('no-store');
    expect(app.sql(`SELECT clicks FROM ad_stats_daily WHERE campaign_id = ${id}`)).toBe('1');

    // A forged link is a 404, and goes nowhere.
    const forged = ad.click_url.replace(/\/[0-9a-f]{32}$/, '/' + '0'.repeat(32));
    expect((await page.request.get(forged, { maxRedirects: 0 })).status()).toBe(404);

    // The report: 1 view, 1 click, 100%; Excel and PDF files download.
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel(`/ad-campaigns/${id}/report`));
    await expect(page.locator('[data-total="نسبة النقر"]')).toHaveText('100.00%');
    await expect(page.locator('[data-total="المشاهدات"]')).toHaveText('1');
    for (const [label, head] of [['تصدير Excel', 'PK'], ['تصدير PDF', '%PDF']] as const) {
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: label }).click()]);
        const bytes = await (await import('node:fs/promises')).readFile((await download.path())!);
        expect(bytes.subarray(0, head.length).toString()).toBe(head);
        expect(download.suggestedFilename()).toMatch(new RegExp(`^ad-report-${id}-`));
    }
});

test('an empty slot shows «أعلن معنا» or nothing, as chosen on the slot screen', async ({ page, app }) => {
    picture(app, 'house-mpu.jpg', 300, 250);
    picture(app, 'house-wide.jpg', 970, 90);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/ad-placements'));
    await row(page, 'مربع جانبي').getByRole('button', { name: 'تعديل' }).click();
    const field = (name: string) => dialog(page).locator(`[id="mountedActionSchema0.${name}"]`).first();

    // A banner of the wrong shape is refused with a message.
    await dialog(page).getByRole('radio', { name: 'إعلان «أعلن معنا»' }).check();
    const choose = async (name: string, option: string, search: string) => {
        await field(name).click();
        await page.getByPlaceholder(/ابدأ بالكتابة للبحث/).locator('visible=true').last().fill(search);
        await page.locator(`[id="${await field(name).getAttribute('aria-controls')}"]`).getByRole('option', { name: option }).first().click();
    };
    await choose('house_desktop_media_id', 'house-wide.jpg', 'house-wide');
    await afterLivewire(page, () => dialog(page).getByRole('button', { name: 'حفظ', exact: true }).click(), 'callMountedAction');
    await expect(page.getByText('لم يُحفظ المكان').first()).toBeVisible();

    await choose('house_desktop_media_id', 'house-mpu.jpg', 'house-mpu');
    await choose('house_mobile_media_id', 'house-mpu.jpg', 'house-mpu');
    await afterLivewire(page, () => dialog(page).getByRole('button', { name: 'حفظ', exact: true }).click(), 'callMountedAction');
    await expect(page.getByText('حُفظ المكان.').first()).toBeVisible();
    await expect(row(page, 'مربع جانبي')).toContainText('«أعلن معنا»');
    expect(slot(await api(page, app, '/ads'), 'sidebar_mpu').house).toMatchObject({ target: '/advertise' });

    await row(page, 'مربع جانبي').getByRole('button', { name: 'تعديل' }).click();
    await dialog(page).getByRole('radio', { name: 'إخفاء المكان' }).check();
    await afterLivewire(page, () => dialog(page).getByRole('button', { name: 'حفظ', exact: true }).click(), 'callMountedAction');
    await expect(row(page, 'مربع جانبي')).toContainText('يختفي');
    expect(slot(await api(page, app, '/ads'), 'sidebar_mpu').house).toBeNull();
});

test('the home lists running campaigns and warns three days before one ends', async ({ page, app }) => {
    campaign(app, 'تنتهي بعد يومين', 50, "now() - interval '1 day'", "now() + interval '2 days'");
    campaign(app, 'تنتهي بعد أسبوع', 50, "now() - interval '1 day'", "now() + interval '7 days'");
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await expect(page.getByText('حملات إعلانية فعّالة (2)')).toBeVisible();
    await expect(page.getByText('الحملة «تنتهي بعد يومين»')).toBeVisible();
    await expect(page.getByText('الحملة «تنتهي بعد أسبوع»')).toHaveCount(0);
});
