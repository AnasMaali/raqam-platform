import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, panel, signIn } from '../support/fixtures';
import { file } from '../support/files';
import { uploadImage } from '../support/panel';

test.describe('media library', () => {
    test('upload a picture, set the focal point, and the copies are made', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const id = await uploadImage(page);

        // The library made the copies (the queue runs inline in these tests): every size that does not enlarge the original.
        const variants = app.sql(`SELECT string_agg(DISTINCT variant, ',' ORDER BY variant) FROM media_variants WHERE media_id = ${id}`);
        for (const name of ['lg', 'md', 'og', 'sm', 'thumb']) expect(variants).toContain(name);
        expect(variants).not.toContain('xl');   // 1920 wide would be a bigger picture than the 1200 px original

        const formats = app.sql(`SELECT string_agg(DISTINCT format, ',' ORDER BY format) FROM media_variants WHERE media_id = ${id}`);
        expect(formats).toContain('jpeg');
        expect(formats).toContain('webp');

        // The files really exist on the disk of this test.
        const paths = app.sql(`SELECT path FROM media_variants WHERE media_id = ${id}`).split('\n');
        expect(paths.length).toBeGreaterThan(5);
        for (const path of paths) expect(existsSync(join(app.storage, 'app/public/media', path)), path).toBe(true);

        // And the public address of a copy serves a real picture.
        const served = await page.request.get(`${app.url}/storage/media/${paths.find((p) => p.endsWith('_md.webp'))}`);
        expect(served.status()).toBe(200);
        expect(served.headers()['content-type']).toBe('image/webp');

        // Focal point: click near the top-left of the picture and save.
        const picture = page.locator('img[x-ref=picture]');
        await expect(picture).toBeVisible();
        const box = (await picture.boundingBox())!;
        await picture.click({ position: { x: box.width * 0.25, y: box.height * 0.2 } });
        await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
        await expect(page.getByText('تم الحفظ')).toBeVisible();

        const [fx, fy] = app.sql(`SELECT focal_x, focal_y FROM media_assets WHERE id = ${id}`).split('|').map(Number);
        expect(fx).toBeCloseTo(0.25, 1);
        expect(fy).toBeCloseTo(0.2, 1);

        // A new focal point queues new copies; with the inline queue they exist right away and nothing was lost.
        const after = app.sql(`SELECT count(*) FROM media_variants WHERE media_id = ${id}`);
        expect(Number(after)).toBeGreaterThan(5);
    });

    test('a forged picture is refused and nothing is stored', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);

        for (const forged of [
            file('holiday.jpg', 'image/jpeg', Buffer.from('<?php echo "owned"; ?>')),
            file('logo.png', 'image/png', Buffer.from('<html><script>alert(1)</script></html>')),
            file('report.pdf', 'application/pdf', Buffer.from('MZ\x90\x00 this is a program, not a document')),
        ]) {
            await page.goto(panel('/media-assets/create'));
            const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.getByText('تصفح').click()]);
            await chooser.setFiles(forged);
            await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible({ timeout: 20_000 });
            await page.locator('input[id="form.alt_text"]').fill('ملف مزوّر');
            await page.getByRole('button', { name: 'إضافة', exact: true }).click();
            const refusal = page.locator('.fi-fo-field-wrp-error-message, [data-validation-error]').first();
            await expect(refusal).toBeVisible();
            await expect(refusal).not.toContainText('validation.');   // a sentence, not a raw key
            await expect(refusal).toContainText('نوع الملف');

            await expect(page).toHaveURL(/media-assets\/create/);   // never reached the edit page
        }

        expect(app.sql('SELECT count(*) FROM media_assets')).toBe('0');
    });
});
