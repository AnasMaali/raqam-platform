import type { Page } from '@playwright/test';
import { test, expect, panel, signIn } from '../support/fixtures';
import type { App } from '../support/app';
import { afterLivewire } from '../support/panel';

// Phase 6: the currency board and the weather, in a real browser, against stand-ins for the Bank of Israel and OpenWeather.

const api = async (page: Page, app: App, path: string) =>
    (await page.request.get(`${app.url}/api/v1${path}`, { headers: { 'X-Raqam-Site-Token': 'e2e-site-token' } })).json();

const row = (page: Page, text: string) => page.locator('tr').filter({ hasText: text }).first();

// A confirmation window is not announced as a dialog, so Filament's own modal window is used.
const dialog = (page: Page) => page.locator('.fi-modal-window:visible').last();

const field = (page: Page, name: string) => dialog(page).locator(`[id="mountedActionSchema0.${name}"]`).first();

/** Runs a dialog's main button and waits until the action has been saved. */
async function submit(page: Page, label: string) {
    await afterLivewire(page, () => dialog(page).getByRole('button', { name: label, exact: true }).click(), 'callMountedAction');
}

/** The dialog's own select (a Filament dropdown). */
async function choose(page: Page, name: string, option: string) {
    const button = field(page, name);
    await button.click();
    await page.locator(`[id="${await button.getAttribute('aria-controls')}"]`).getByRole('option', { name: option }).first().click();
}

/** Drags a table row (in reordering mode) above the first row. Repeated from where it landed if a slow machine stops short. */
async function dragToTop(page: Page, text: string) {
    await page.getByRole('button', { name: 'إعادة ترتيب السجلات' }).click();
    const rows = page.locator('[x-sortable-item]');
    for (let attempt = 0; attempt < 3 && !(await rows.first().innerText()).includes(text); attempt++) {
        const handle = rows.filter({ hasText: text }).first();   // the whole row is the handle
        await handle.hover();
        const from = (await handle.boundingBox())!;
        const to = (await rows.first().boundingBox())!;
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();
        await page.mouse.move(from.x + from.width / 2, from.y - 10, { steps: 5 });
        await page.mouse.move(from.x + from.width / 2, (from.y + to.y) / 2, { steps: 20 });
        await page.mouse.move(from.x + from.width / 2, to.y - 8, { steps: 20 });
        await page.waitForTimeout(300);
        await page.mouse.up();
        await page.waitForTimeout(500);
    }
    await page.getByRole('button', { name: 'إنهاء إعادة ترتيب السجلات' }).click();
}

test('currencies: fetched from the bank, a currency added and dragged first, spreads set the buy and sell rates', async ({ page, app }) => {
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/currencies'));

    await page.getByRole('button', { name: 'جلب الأسعار الآن' }).click();
    await afterLivewire(page, () => dialog(page).getByRole('button', { name: 'تأكيد' }).click(), 'callMountedAction');
    await expect(page.getByText('أُرسل الطلب.').first()).toBeVisible();     // a job (the test queue runs it at once)
    await page.reload();
    await expect(row(page, 'دولار أمريكي')).toContainText('3.106');
    await expect(row(page, 'درهم إماراتي')).toContainText('من الدولار بالربط الثابت');

    // Add the Swiss franc: chosen from what the bank publishes; its name fills in by itself.
    await page.getByRole('button', { name: 'إضافة عملة' }).click();
    await choose(page, 'code', 'CHF — فرنك سويسري');
    await expect(field(page, 'name_ar')).toHaveValue('فرنك سويسري');
    await submit(page, 'إضافة');
    await expect(row(page, 'فرنك سويسري')).toBeVisible();
    await app.artisanAsync(['currency:fetch']);
    await page.reload();
    await expect(row(page, 'فرنك سويسري')).toContainText('3.768');

    await dragToTop(page, 'فرنك سويسري');
    await expect.poll(async () => (await api(page, app, '/currencies')).data[0].code).toBe('CHF');
    expect(app.sql("SELECT currency_code FROM rate_board_currencies ORDER BY sort_order LIMIT 1")).toBe('CHF');

    // The dollar's spreads: 1 % under and 1.5 % over the official rate.
    await row(page, 'دولار أمريكي').getByRole('button', { name: 'تعديل' }).click();
    await field(page, 'buy_percent').fill('1');
    await field(page, 'sell_percent').fill('1.5');
    await submit(page, 'حفظ');
    const usd = (await api(page, app, '/currencies')).data.find((c: { code: string }) => c.code === 'USD');
    expect(usd).toMatchObject({ mid: '3.106', buy: '3.075', sell: '3.153', is_manual: false });
    await expect(row(page, 'دولار أمريكي')).toContainText('−1%');

    // A spread out of range is refused on the spot.
    await row(page, 'دولار أمريكي').getByRole('button', { name: 'تعديل' }).click();
    await field(page, 'buy_percent').fill('35');
    await dialog(page).getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(dialog(page).getByText(/النسبة بين 0 و20/)).toBeVisible();
});

test('a manual rate until a chosen time shows at once, then the official rate comes back by itself', async ({ page, app }) => {
    test.setTimeout(300_000);
    await app.artisanAsync(['currency:fetch']);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/currencies'));

    // Palestine time, two to three minutes ahead (the picker has no seconds).
    const target = await page.evaluate(() => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Hebron', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date(Date.now() + 180_000));
        return Object.fromEntries(parts.map((x) => [x.type, x.value]));
    });

    await row(page, 'دولار أمريكي').getByRole('button', { name: 'سعر يدوي' }).click();
    await field(page, 'buy').fill('3.05');
    await field(page, 'sell').fill('3.20');
    await field(page, 'until').click();
    const picker = page.locator('[id="mountedActionSchema0.until-panel"]');
    await picker.locator(`[data-date="${target.year}-${target.month}-${target.day}"]`).click();
    await picker.getByLabel('الساعة').fill(String(Number(target.hour)));
    await picker.getByLabel('الدقيقة').fill(String(Number(target.minute)));
    await picker.getByLabel('الدقيقة').blur();
    await page.waitForTimeout(800);
    await submit(page, 'حفظ السعر اليدوي');

    await expect(row(page, 'دولار أمريكي')).toContainText(`حتى ${target.year}-${target.month}-${target.day} ${target.hour}:${target.minute}`);
    expect((await api(page, app, '/currencies')).data[0]).toMatchObject({ code: 'USD', buy: '3.050', sell: '3.200', is_manual: true });
    expect(app.sql("SELECT to_char(valid_until AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD HH24:MI') FROM rate_overrides"))
        .toBe(`${target.year}-${target.month}-${target.day} ${target.hour}:${target.minute}`);
    expect(Number(app.sql("SELECT count(*) FROM audit_log WHERE subject_table = 'rate_overrides'"))).toBe(1);

    // Nobody touches anything: at that minute the board is back to the official rate and spread.
    await expect.poll(async () => (await api(page, app, '/currencies')).data[0].is_manual, { timeout: 240_000, intervals: [5_000] }).toBe(false);
    expect((await api(page, app, '/currencies')).data[0]).toMatchObject({ buy: '3.090', sell: '3.122' });
    await page.reload();
    await expect(row(page, 'دولار أمريكي')).not.toContainText('حتى');
});

test('when the bank fails three times the last good rates stay, and the home says so until it answers again', async ({ page, app, markets }) => {
    await app.artisanAsync(['currency:fetch']);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    markets.answers.boiStatus = 503;

    await page.goto(panel('/currencies'));
    for (let i = 0; i < 3; i++) {
        await page.getByRole('button', { name: 'جلب الأسعار الآن' }).click();
        await afterLivewire(page, () => dialog(page).getByRole('button', { name: 'تأكيد' }).click(), 'callMountedAction');
        await expect(page.getByText('أُرسل الطلب.').first()).toBeVisible();
        await page.reload();
        await expect(page.getByText(`فشل متتالٍ: ${i + 1}`).first()).toBeVisible();
    }

    expect((await api(page, app, '/currencies')).data[0]).toMatchObject({ code: 'USD', mid: '3.106' });
    await page.goto(panel(''));
    await expect(page.getByText('مصدر العملات (بنك إسرائيل) فشل 3 مرات متتالية').first()).toBeVisible();
    await expect(page.getByText('الموقع يعرض آخر أسعار صحيحة').first()).toBeVisible();
    await expect(page.getByText('العملات — لا يستجيب')).toBeVisible();
    expect(Number(app.sql("SELECT count(*) FROM admin_notifications WHERE dedupe_key = 'provider:bank_of_israel'"))).toBe(1);

    // The bank is back with a new day's rates.
    markets.answers.boiStatus = 200;
    markets.answers.boi.USD = '3.12';
    markets.answers.boiTime = new Date(Date.now() - 60_000).toISOString();
    await app.artisanAsync(['currency:fetch']);
    await page.reload();
    await expect(page.getByText('فشل 3 مرات متتالية')).toHaveCount(0);
    await expect(page.getByText('العملات — يعمل')).toBeVisible();
    expect((await api(page, app, '/currencies')).data[0]).toMatchObject({ mid: '3.120', direction: 'up' });
});

test('weather: cities fetched, one renamed and moved, coordinates checked, and the key never kept anywhere', async ({ page, app, markets }) => {
    await app.artisanAsync(['weather:fetch', 'نابلس']);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/weather-cities'));
    await expect(row(page, 'نابلس')).toContainText('24.3° صافٍ');

    await row(page, 'جنين').getByRole('button', { name: 'تعديل' }).click();
    await field(page, 'latitude').fill('35.2939');                     // longitude typed into latitude
    await field(page, 'longitude').fill('32.4646');
    await dialog(page).getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(dialog(page).getByText(/خارج فلسطين/).first()).toBeVisible();

    await field(page, 'name_ar').fill('جنين المدينة');
    await field(page, 'latitude').fill('32.4610');
    await field(page, 'longitude').fill('35.3000');
    await submit(page, 'حفظ');
    await expect(row(page, 'جنين المدينة')).toContainText('32.461000, 35.300000');

    const city = (await api(page, app, '/weather/cities')).data.find((c: { slug: string }) => c.slug === 'جنين');
    expect(city).toMatchObject({ name: 'جنين المدينة', path: '/weather/جنين', latitude: 32.461, longitude: 35.3 });

    const nablus = await api(page, app, `/weather/${encodeURIComponent('نابلس')}`);
    expect(nablus.meta.attribution).toBe('Weather data © OpenWeather');
    expect(nablus.data.now).toMatchObject({ temperature: 24.3, humidity: 45, wind_kmh: 9 });
    expect(nablus.data.forecast).toHaveLength(5);

    // The key travelled to OpenWeather in the address, and is nowhere in our own records.
    expect(markets.requests.some((r) => r.includes('appid=e2e-fake-openweather-key'))).toBe(true);
    markets.answers.owStatus = 401;
    await expect(app.artisanAsync(['weather:fetch', 'نابلس'])).rejects.toThrow();
    for (const place of [app.log(), app.dump(), JSON.stringify(await api(page, app, '/weather/widget'))]) {
        expect(place).not.toContain('e2e-fake-openweather-key');
        expect(place).not.toContain('appid');
    }
    await page.goto(panel(''));
    await expect(page.getByText('الطقس — لا يستجيب')).toBeVisible();
    await expect(page.getByText('e2e-fake-openweather-key')).toHaveCount(0);
});
