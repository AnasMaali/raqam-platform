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

/** Chooses an option of a Filament select (the custom dropdown, not a native one). */
export async function pick(page: Page, fieldId: string, option: string, search?: string) {
    const button = page.locator(`[id="form.${fieldId}"]`).first();
    await button.click();
    const list = page.locator(`[id="${await button.getAttribute('aria-controls')}"]`);
    if (search !== undefined) {
        await page.getByPlaceholder(/ابدأ بالكتابة للبحث/).locator('visible=true').last().fill(search);
    }
    await list.getByRole('option', { name: option }).first().click();
}

/** The create screen: kind and title, then the editor opens on the saved draft. Returns the item's id. */
export async function createPost(page: Page, kindLabel: string, title: string): Promise<number> {
    await page.goto(panel('/posts/create'));
    if (kindLabel !== 'خبر') {
        await pick(page, 'kind_code', kindLabel);
    }
    await page.locator('input[id="form.title"]').fill(title);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/posts\/\d+\/edit/);
    return Number(page.url().match(/posts\/(\d+)\/edit/)![1]);
}

export async function writeBody(page: Page, text: string) {
    const editor = page.locator('.tiptap.ProseMirror').first();
    await editor.click();
    await editor.pressSequentially(text, { delay: 5 });
}

export async function save(page: Page) {
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await expect(page.getByText('تم الحفظ')).toBeVisible();
}

/** A published news item made through the editor (the quickest complete item), returning its id. */
export async function publishedNews(page: Page, title: string, body = 'نص الخبر الأصلي.'): Promise<number> {
    const id = await createPost(page, 'خبر', title);
    await page.locator('textarea[id="form.excerpt"]').fill('ملخص الخبر');
    await writeBody(page, body);
    await pick(page, 'category_id', 'محلي (نابلس)');
    await pick(page, 'cover_media_id', 'cover.png', 'cover');
    await page.getByRole('button', { name: 'نشر الآن' }).click();
    await page.getByRole('button', { name: 'تأكيد' }).click();
    await expect(page.getByText('تم الحفظ')).toBeVisible();
    return id;
}

/** The fields every item needs before it can leave the draft state, short of publishing. */
export async function fillRequired(page: Page, body = 'نص الخبر.') {
    await page.locator('textarea[id="form.excerpt"]').fill('ملخص الخبر');
    await writeBody(page, body);
    await pick(page, 'category_id', 'محلي (نابلس)');
    await pick(page, 'cover_media_id', 'cover.png', 'cover');
}
