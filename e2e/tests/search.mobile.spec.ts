import { test, expect, panel, signIn } from '../support/fixtures';

// The search screens of the panel on a phone (Pixel 7, 412 px): nothing sticks out sideways.

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('synonyms, search statistics and quick search fit a phone', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    app.artisan(['synonyms:install']);
    for (const q of ['أسعار الذهب', 'سعر صرف الدولار أمام الشيكل اليوم في نابلس', 'كلمة طويلة جداً جداً جداً لا توجد في أي مادة منشورة']) {
        await page.request.get(`${app.url}/api/v1/search?${new URLSearchParams({ q, track: '1' })}`, { headers: { 'X-Raqam-Site-Token': 'e2e-site-token' } });
    }

    for (const [name, path] of [['synonyms', '/synonyms'], ['new synonym group', '/synonyms/create'], ['search statistics', '/search-statistics']] as const) {
        await page.goto(panel(path));
        await expect(page.locator('main, .fi-main').first()).toBeVisible();
        await noSideScroll(page, name);
    }
    await expect(page.getByText('اسعار الذهب').first()).toBeVisible();

    const group = app.sql('SELECT min(id) FROM synonym_groups');
    await page.goto(panel(`/synonyms/${group}/edit`));
    await noSideScroll(page, 'synonym group editor');

    // Quick search from the phone's top bar finds a draft.
    app.sql("INSERT INTO posts (kind_code, title, slug) VALUES ('news', 'أسعار الطاقة الشمسية', 'draft-solar')");
    await page.goto(panel(''));
    const box = page.getByPlaceholder('بحث').first();
    await box.fill('اسعار الطاقه');
    await expect(page.locator('.fi-global-search-results-ctn').getByText('أسعار الطاقة الشمسية').first()).toBeVisible();
    await noSideScroll(page, 'quick search results');
});
