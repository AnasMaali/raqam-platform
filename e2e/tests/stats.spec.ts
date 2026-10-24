import { readFileSync } from 'node:fs';
import { test, expect, panel, signIn } from '../support/fixtures';
import { hit, livePost } from '../support/stats';
import { afterLivewire } from '../support/panel';

// Phase 9: visits counted through the site's server, moved to PostgreSQL by the hourly transfer, shown on the statistics
// screen, exported, and the missing pages turned into redirects.

test('the site reports visits; robots, strangers and other sites are not counted; nothing about the address is kept', async ({ page, app }) => {
    const post = livePost(app, 'خبر الأسعار اليوم');

    const counted = await hit(page, app, { type: 'view', path: `/news/${post}/x?utm_source=whatsapp`, post_id: post });
    expect(counted.status()).toBe(204);
    expect(counted.headers()['set-cookie']).toBeUndefined();
    expect(counted.headers()['access-control-allow-origin']).toBeUndefined();
    await hit(page, app, { type: 'view', path: '/', referrer: 'https://www.google.com/' }, '198.51.100.20');
    await hit(page, app, { type: 'view', path: '/' }, '198.51.100.30', 'Googlebot/2.1 (+http://www.google.com/bot.html)');
    await hit(page, app, { type: 'view', path: '/' }, '198.51.100.40', undefined, null);
    await hit(page, app, { type: 'view', path: '/' }, '198.51.100.50', undefined, 'wrong-token');
    await hit(page, app, { type: 'event', path: '/', event: 'share_click' });
    await hit(page, app, { type: 'not_found', path: '/old-page?fbclid=x' });
    expect((await hit(page, app, { type: 'view', path: '//evil.example' })).status()).toBe(422);

    // The address and its plain hashes are nowhere in Redis: not in a key, not in a value.
    const keys = app.redisKeys().filter((k) => k.includes('stats:'));
    expect(keys.length).toBeGreaterThan(0);
    for (const k of keys) expect(k).not.toContain('203.0.113.7');

    app.artisan(['stats:flush']);
    const today = app.sql(`SELECT to_char(now() AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD')`);
    expect(app.sql(`SELECT page_views || '/' || unique_visitors FROM site_stats_daily WHERE stat_date = '${today}'`)).toBe('2/2');
    expect(app.sql(`SELECT string_agg(source_code || '=' || visits, ',' ORDER BY source_code) FROM traffic_sources_daily`)).toBe('google=1,whatsapp=1');
    expect(app.sql(`SELECT views FROM post_views_daily WHERE post_id = ${post}`)).toBe('1');
    expect(app.sql(`SELECT count FROM event_stats_daily WHERE event_code = 'share_click'`)).toBe('1');
    expect(app.sql(`SELECT path FROM not_found_paths`)).toBe('/old-page');
    expect(app.dump()).not.toContain('203.0.113.7');

    // The same transfer again changes nothing.
    app.artisan(['stats:flush']);
    expect(app.sql(`SELECT page_views FROM site_stats_daily WHERE stat_date = '${today}'`)).toBe('2');

    // Most read for the site; the number stays hidden until the admin shows it (22.12).
    const mostRead = await (await page.request.get(`${app.url}/api/v1/posts/most-read`)).json();
    expect(mostRead.data.map((p: { id: number }) => p.id)).toEqual([post]);
    expect(mostRead.data[0].views).toBeUndefined();
    app.sql(`UPDATE feature_flags SET is_enabled = true WHERE key = 'public_view_counts'`);
    const shown = await (await page.request.get(`${app.url}/api/v1/posts/${post}`)).json();
    expect(shown.data.views).toBe(1);
});

test('a page on another site cannot report visits from a visitor\'s browser (no CORS)', async ({ page, app }) => {
    // localhost and 127.0.0.1 are two origins: the page is on one, the API on the other.
    await page.goto(app.url.replace('127.0.0.1', 'localhost') + '/up');
    const outcome = await page.evaluate(async (url) => {
        try {
            await fetch(`${url}/api/v1/stats/hits`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Raqam-Site-Token': 'e2e-site-token' }, body: '{"type":"view","path":"/"}' });
            return 'sent';
        } catch {
            return 'blocked';
        }
    }, app.url);
    expect(outcome).toBe('blocked');
    expect(app.redisKeys().filter((k) => k.includes('stats:') && !k.includes(':salt'))).toEqual([]);
});

test('the statistics screen: numbers, chart, period, Excel export, and not a single request while it sits open', async ({ page, app, adminPassword }) => {
    test.setTimeout(150_000);
    const post = livePost(app, 'خبر الذهب والدولار');
    const yesterday = app.sql(`SELECT to_char((now() AT TIME ZONE 'Asia/Hebron')::date - 1, 'YYYY-MM-DD')`);
    app.sql(`INSERT INTO site_stats_daily VALUES ('${yesterday}', 40, 12)`);
    app.sql(`INSERT INTO traffic_sources_daily VALUES ('${yesterday}', 'facebook', 9)`);
    app.sql(`INSERT INTO post_views_daily VALUES (${post}, '${yesterday}', 7)`);
    await hit(page, app, { type: 'view', path: `/news/${post}/x`, post_id: post, referrer: 'https://www.google.com/' });

    await signIn(page, app, adminPassword);
    await page.goto(panel('/statistics'));
    const main = page.locator('.fi-main');
    await expect(main.locator('[data-tile="مشاهدات الصفحات"]')).toHaveText('41');   // yesterday stored + today live
    await expect(main.locator(`[data-day="${yesterday}"]`)).toHaveCount(1);
    await expect(main.getByText('خبر الذهب والدولار')).toBeVisible();
    await expect(main.locator('[data-row="facebook"]')).toBeVisible();
    await expect(main.locator('[data-row="google"]')).toBeVisible();

    // Today only: yesterday's figures leave the tiles.
    await afterLivewire(page, () => page.getByRole('combobox', { name: 'الفترة' }).selectOption({ label: 'اليوم' }));
    await expect(main.locator('[data-tile="مشاهدات الصفحات"]')).toHaveText('1');
    await expect(main.locator('[data-row="facebook"]')).toHaveCount(0);

    // Nothing polls: twenty seconds without any request of the page.
    const requests: string[] = [];
    page.on('request', (r) => requests.push(r.url()));
    await page.waitForTimeout(20_000);
    expect(requests.filter((u) => u.includes('/livewire/'))).toEqual([]);

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'تصدير Excel' }).click()]);
    expect(download.suggestedFilename()).toMatch(/^statistics-\d{4}-\d{2}-\d{2}-\d{4}-\d{2}-\d{2}\.xlsx$/);
    const bytes = readFileSync((await download.path())!);
    expect(bytes.subarray(0, 2).toString()).toBe('PK');
    expect(app.sql(`SELECT count(*) FROM audit_log WHERE action = 'export'`)).toBe('1');
});

test('a missing page becomes a redirect from its own screen, and is then handled', async ({ page, app, adminPassword }) => {
    await hit(page, app, { type: 'not_found', path: '/news/9/عنوان-قديم' });
    await signIn(page, app, adminPassword);
    await page.goto(panel('/not-found-paths'));
    const row = page.locator('.fi-ta-row').filter({ hasText: '/news/9/عنوان-قديم' });
    await expect(row).toBeVisible();

    await row.getByRole('link', { name: 'اعمل تحويل' }).click();
    await page.waitForURL(/redirects/);
    const dialog = page.locator('.fi-modal-window:visible');
    await expect(dialog.locator('input[id$="from_path"]')).toHaveValue('/news/9/عنوان-قديم');
    await dialog.locator('input[id$="to_path"]').fill('/news/9/عنوان-جديد');
    await afterLivewire(page, () => dialog.getByRole('button', { name: 'إضافة', exact: true }).click());

    expect(app.sql(`SELECT to_path FROM redirects WHERE from_path = '/news/9/عنوان-قديم'`)).toBe('/news/9/عنوان-جديد');
    expect(app.sql(`SELECT resolved_at IS NOT NULL FROM not_found_paths`)).toBe('t');
    await page.goto(panel('/not-found-paths'));
    await expect(page.locator('.fi-main').getByText('/news/9/عنوان-قديم')).toHaveCount(0);
});

test('sign-ins, right and wrong, and every change are on the read-only screens, searchable by address', async ({ page, browser, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    // A wrong password from another browser (a code is never sent for it, and the screen does not say so).
    const other = await browser.newPage();
    await other.goto(app.url + panel('/login'));
    await other.getByLabel('البريد الإلكتروني').fill('e2e-admin@example.test');
    await other.locator('input[type=password], input[id="form.password"]').first().fill('wrong-password-123');
    await other.getByRole('button', { name: 'تسجيل الدخول' }).click();
    await other.waitForURL(/login\/code/);
    await other.close();

    await page.goto(panel('/settings'));
    await page.locator('input[id$="general__site_name"]').first().fill('رَقَم الاقتصادي');
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ', exact: true }).click(), 'save');

    await page.goto(panel('/login-attempts'));
    const attempts = page.locator('.fi-ta-row');
    await expect(attempts.filter({ hasText: 'نجح' })).toHaveCount(1);
    await expect(attempts.filter({ hasText: 'بريد أو كلمة مرور خاطئة' })).toHaveCount(1);
    await expect(page.locator('.fi-main').getByText('127.0.0.1')).toHaveCount(0);

    await page.goto(panel('/audit-logs'));
    await page.getByPlaceholder(/ابحث/).fill('رَقَم الاقتصادي');
    const entry = page.locator('.fi-ta-row').filter({ hasText: 'تغيير إعدادات' });
    await expect(entry).toHaveCount(1);
    await expect(page.locator('.fi-ta-row').getByRole('button', { name: /حذف|تعديل/ })).toHaveCount(0);
    await entry.getByRole('link', { name: 'عرض' }).click();
    await expect(page.locator('.fi-main').getByText('رَقَم الاقتصادي')).toBeVisible();
    await expect(page.locator('.fi-main').getByText('Changes')).toHaveCount(0);
    const id = page.url().match(/audit-logs\/(\d+)/)![1];
    expect((await page.request.get(`${app.url}${panel(`/audit-logs/${id}/edit`)}`)).status()).toBe(404);
});
