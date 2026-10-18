import { expect, type Page } from '@playwright/test';
import { panel } from './fixtures';
import { file, png } from './files';

/** Uploads a picture through the media library screen, as an editor does, and returns the new asset's id. */
export async function uploadImage(page: Page, name = 'cover.png', alt = 'صورة اختبار', size: [number, number] = [1200, 800]): Promise<number> {
    await page.goto(panel('/media-assets/create'));
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.getByText('تصفح').click()]);
    await chooser.setFiles(file(name, 'image/png', png(...size)));
    await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible({ timeout: 20_000 });
    await page.locator('input[id="form.alt_text"]').fill(alt);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/media-assets\/\d+\/edit/);
    return Number(page.url().match(/media-assets\/(\d+)\/edit/)![1]);
}
