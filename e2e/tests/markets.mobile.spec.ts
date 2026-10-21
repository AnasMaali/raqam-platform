import { test, expect, panel, signIn } from '../support/fixtures';

// The currency and weather screens of the panel, and its home, on a phone (Pixel 7, 412 px): nothing sticks out sideways.

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('currencies, the manual-rate dialog, weather cities and the home fit a phone', async ({ page, app }) => {
    await app.artisanAsync(['currency:fetch']);
    await app.artisanAsync(['weather:fetch', 'نابلس']);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);

    await page.goto(panel('/currencies'));
    await expect(page.getByText('دولار أمريكي').first()).toBeVisible();
    await noSideScroll(page, 'currencies');

    await page.locator('tr').filter({ hasText: 'دولار أمريكي' }).first().getByRole('button', { name: 'سعر يدوي' }).click();
    await expect(page.locator('.fi-modal-window:visible').last().locator('[id="mountedActionSchema0.buy"]')).toBeVisible();
    await noSideScroll(page, 'manual-rate dialog');
    await page.keyboard.press('Escape');

    await page.goto(panel('/weather-cities'));
    await expect(page.getByText('نابلس').first()).toBeVisible();
    await noSideScroll(page, 'weather cities');

    await page.goto(panel(''));
    await expect(page.getByText('حالة الربط الخارجي')).toBeVisible();
    await noSideScroll(page, 'home with the connection status');
});
