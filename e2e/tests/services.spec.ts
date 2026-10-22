import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, pick } from '../support/panel';
import { PERSON_AGENT, SITE, api, dropCaches, hebronParts, setDateTime } from '../support/ads';

// Phase 7: services and prices in a real browser.
test.use({ userAgent: PERSON_AGENT });

const saveForm = async (page: import('@playwright/test').Page) =>
    afterLivewire(page, () => page.getByRole('button', { name: 'حفظ التغييرات' }).click(), 'save');

test('the starting services, a refused price, then a special offer that goes back to the regular price by itself', async ({ page, app }) => {
    test.setTimeout(360_000);
    app.artisan(['services:install']);
    app.sql(`UPDATE settings SET value = '+970599123456' WHERE key = 'contact.whatsapp'`);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);

    await page.goto(panel('/services'));
    const episode = page.locator('tr').filter({ hasText: 'حلقة بودكاست' }).first();
    await expect(episode).toContainText('السعر عند التواصل');
    await episode.getByRole('link', { name: 'تعديل' }).click();
    await page.waitForURL(/services\/\d+\/edit/);

    // Negative, then three decimals: refused under the field, nothing saved.
    await page.getByLabel('سعر ثابت').check();
    await page.locator('[id="form.price"]').fill('-123.45');
    await pick(page, 'currency_code', 'شيكل');
    await saveForm(page);
    await expect(page.getByText('المبلغ لا يكون سالباً.')).toBeVisible();
    await page.locator('[id="form.price"]').fill('123.456');
    await saveForm(page);
    await expect(page.getByText('بمنزلتين عشريتين على الأكثر')).toBeVisible();
    expect(app.sql(`SELECT price_mode FROM services WHERE slug = 'podcast-episode'`)).toBe('on_request');

    // 123.45 an episode, an offer of 99.99 until two to three minutes from now, and shown on the site (made-up amounts).
    await page.locator('[id="form.price"]').fill('123.45');
    await page.locator('[id="form.price_note"]').fill('للحلقة');
    await page.getByLabel('عرض خاص').check();
    await page.locator('[id="form.sale_price"]').fill('99.99');
    await setDateTime(page, 'form.sale_ends_at', await hebronParts(page, 180_000));
    await page.getByLabel('ظاهرة على الموقع').check();
    await saveForm(page);
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();
    expect(app.sql(`SELECT price_amount || ' ' || sale_price_amount FROM services WHERE slug = 'podcast-episode'`)).toBe('12345 9999');

    const shown = (await api(page, app, '/services/podcast-episode')).data;
    expect(shown.price).toMatchObject({ amount: '99.99', text: '99.99 ₪ للحلقة', offer: { was: '123.45' } });
    expect(shown.contact.whatsapp).toContain(encodeURIComponent('«حلقة بودكاست»'));
    expect(shown.booking).toEqual({ enabled: false, path: null });
    expect(shown.structured_data.offers).toMatchObject({ '@type': 'Offer', price: '99.99', priceCurrency: 'ILS' });
    expect(app.sql(`SELECT new_value FROM audit_log_changes WHERE field = 'price' ORDER BY audit_id DESC LIMIT 1`)).toContain('123.45 ILS (سعر ثابت) للحلقة — عرض 99.99');

    // When the offer ends the regular price is back, with nobody touching anything.
    await expect.poll(async () => (await api(page, app, '/services/podcast-episode')).data.price, { timeout: 240_000, intervals: [5_000] })
        .toMatchObject({ amount: '123.45', offer: null });
});

test('contact presses are counted per service and button; booking appears only when switched on; the advertise page lists the packages', async ({ page, app }) => {
    app.artisan(['services:install']);
    app.sql(`UPDATE services SET is_active = true`);
    app.sql(`UPDATE services SET price_mode = 'fixed', price_amount = 11111, currency_code = 'ILS', price_note = 'للشهر' WHERE slug = 'ad-packages'`);
    dropCaches(app);
    const press = (channel: string, ip: string) => page.request.post(`${app.url}/api/v1/services/podcast-episode/contact`, {
        headers: { ...SITE, 'X-Raqam-Visitor-Ip': ip, 'X-Raqam-Visitor-Ua': PERSON_AGENT }, data: { channel },
    });
    expect((await press('whatsapp', '203.0.113.10')).status()).toBe(204);
    expect((await press('whatsapp', '203.0.113.10')).status()).toBe(204);    // the same press again: once
    expect((await press('phone', '203.0.113.11')).status()).toBe(204);
    expect(app.sql(`SELECT string_agg(channel || '=' || clicks, ',' ORDER BY channel) FROM service_contact_clicks_daily`)).toBe('phone=1,whatsapp=1');

    const advertise = (await api(page, app, '/advertise')).data;
    expect(advertise.packages.map((p: any) => [p.name, p.price.text])).toEqual([['باقات إعلانية', '111.11 ₪ للشهر']]);
    expect(advertise.placements.map((p: any) => p.code)).toEqual(['header_leaderboard', 'sidebar_mpu', 'in_article', 'between_sections']);

    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/services'));
    await expect(page.locator('tr').filter({ hasText: 'حلقة بودكاست' }).first()).toContainText('2');

    // Switching booking on from the feature switches shows the button by itself.
    app.sql(`UPDATE feature_flags SET is_enabled = true WHERE key = 'booking'`);
    dropCaches(app);
    expect((await api(page, app, '/services/podcast-episode')).data.booking).toEqual({ enabled: true, path: '/services/podcast-episode/book' });
});
