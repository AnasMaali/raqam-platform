import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, panel, signIn } from '../support/fixtures';
import { file } from '../support/files';
import { mp4, probe, probeBytes } from '../support/clips';
import { pick } from '../support/panel';

async function chooseClip(page: import('@playwright/test').Page, upload: { name: string; mimeType: string; buffer: Buffer }, title: string) {
    await page.goto(panel('/videos/create'));
    await pick(page, 'source', 'مقطع قصير من الجهاز (MP4)');
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.getByText('تصفح').click()]);
    await chooser.setFiles(upload);
    await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible({ timeout: 30_000 });
    await page.locator('input[id="form.upload_title"]').fill(title);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
}

test.describe('short clips', () => {
    test('a real MP4 is accepted, its position and device are wiped from the stored file', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        const clip = mp4(3, true);
        expect(probeBytes(clip)).toMatch(/32\.2211/);   // the test file really carries a position

        await chooseClip(page, file('market.mp4', 'video/mp4', clip), 'لقطة من السوق');
        await page.waitForURL(/videos\/\d+\/edit/);

        expect(app.sql("SELECT source, display_title, duration_seconds FROM videos")).toBe('upload|لقطة من السوق|3');
        const [path, mime, w, h] = app.sql("SELECT m.path, m.mime_type, m.width, m.height FROM videos v JOIN media_assets m ON m.id = v.upload_media_id").split('|');
        expect([mime, w, h]).toEqual(['video/mp4', '96', '64']);

        const stored = join(app.storage, 'app/public/media', path);
        expect(existsSync(stored)).toBe(true);
        const info = probe(stored);
        expect(info).not.toMatch(/ISO6709|32\.2211|"location/);

        // The public address serves it as a video, and the API gives the visitor the file, not a YouTube player.
        const served = await page.request.get(`${app.url}/storage/media/${path}`);
        expect(served.status()).toBe(200);
        expect(served.headers()['content-type']).toContain('video/mp4');
        expect((await served.body()).includes(Buffer.from('+32.2211'))).toBe(false);
    });

    test('a clip longer than 90 seconds is refused with the reason, and nothing is stored', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);

        await chooseClip(page, file('long.mp4', 'video/mp4', mp4(95, false)), 'طويل');

        await expect(page.locator('.fi-fo-field-wrp-error-message, [data-validation-error]').first()).toContainText('90');
        await expect(page).toHaveURL(/videos\/create/);
        expect(app.sql('SELECT count(*) FROM videos')).toBe('0');
        expect(app.sql('SELECT count(*) FROM media_assets')).toBe('0');
    });

    test('a forged clip (a script, or only a header) is refused', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);

        for (const forged of [
            file('clip.mp4', 'video/mp4', Buffer.from('<?php system($_GET["c"]); ?>')),
            file('clip.mp4', 'video/mp4', Buffer.concat([Buffer.from([0, 0, 0, 0x18]), Buffer.from('ftypmp42'), Buffer.alloc(8), Buffer.from('mp42isom'), Buffer.alloc(3000, 1)])),
        ]) {
            await chooseClip(page, forged, 'مزوّر');
            await expect(page.locator('.fi-fo-field-wrp-error-message, [data-validation-error]').first()).toBeVisible();
            await expect(page).toHaveURL(/videos\/create/);
        }

        expect(app.sql('SELECT count(*) FROM videos')).toBe('0');
        expect(app.sql('SELECT count(*) FROM media_assets')).toBe('0');
    });
});
