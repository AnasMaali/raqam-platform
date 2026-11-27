import { test, expect, panel, signIn } from '../support/fixtures';
import { createPost, uploadImage, afterLivewire } from '../support/panel';
import type { Locator, Page } from '@playwright/test';

// The posts list and the media library on the narrowest phone the panel supports (360 px), as a finger uses them.
test.use({ viewport: { width: 360, height: 780 } });

/** How far a list's content runs past its box (0 when it fits). */
const overflow = (page: Page) => page.locator('.fi-ta-content-ctn').evaluate((e) => e.scrollWidth - e.clientWidth);

const box = async (locator: Locator) => (await locator.boundingBox())!;

test('the posts list at 360 px: one row per post, every tap target 44 px, and rows are still selected for a bulk action', async ({ page, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    const first = await createPost(page, 'خبر', 'خبر أول بعنوان طويل يلتف على سطرين في شاشة الهاتف الضيقة');
    const second = await createPost(page, 'خبر', 'خبر ثان');
    app.sql(`UPDATE posts SET is_featured = true, breaking_until = now() + interval '2 hours' WHERE id = ${first}`);

    await page.goto(panel('/posts'));
    const rows = page.locator('.fi-ta-table > tbody > tr.fi-ta-row');
    await expect(rows).toHaveCount(2);
    expect(await overflow(page), 'the list runs past the screen').toBeLessThanOrEqual(0);

    for (const row of await rows.all()) {
        // No column headings in a row: its line reads on its own.
        await expect(row.locator('.fi-ta-cell-label:visible')).toHaveCount(0);
        // The picture and the title are links to the editor, each a finger high; the box's cell too.
        for (const part of ['.fi-ta-cell-cover .fi-ta-col', '.fi-ta-cell-title .fi-ta-col', '.fi-ta-selection-cell']) {
            const b = await box(row.locator(part));
            expect(b.height, `${part} height`).toBeGreaterThanOrEqual(44);
        }
        expect((await box(row.locator('.fi-ta-selection-cell'))).width, 'selection cell width').toBeGreaterThanOrEqual(44);
        expect((await box(row.locator('.fi-ta-record-checkbox'))).width, 'the box itself').toBeGreaterThanOrEqual(24);
        // The kind, state and date sit on a line under the title, not beside it.
        const title = await box(row.locator('.fi-ta-cell-title'));
        expect((await box(row.locator('.fi-ta-cell-status'))).y, 'the state is under the title').toBeGreaterThanOrEqual(title.y + title.height - 1);
    }

    // «عاجل» and «مميز»: badges in the line under the title when so; nothing, not even an empty gap, when not.
    const flagged = rows.filter({ hasText: 'خبر أول' });
    const title = await box(flagged.locator('.fi-ta-cell-title'));
    for (const [cell, text] of [['.fi-ta-cell-breaking', 'عاجل'], ['.fi-ta-cell-is-featured', 'مميز']]) {
        const badge = flagged.locator(`${cell} .fi-badge`);
        await expect(badge).toBeVisible();
        await expect(badge).toHaveText(text);
        expect((await box(badge)).y, `${text} is under the title`).toBeGreaterThanOrEqual(title.y + title.height - 1);
        // Out of the line altogether: an empty cell left in it would still take a gap.
        expect(await rows.filter({ hasText: 'خبر ثان' }).locator(cell).evaluate((e) => getComputedStyle(e).display), `${text} on a row without it`).toBe('none');
    }

    // Selecting by touch: one row, then the other; the bulk action runs on both.
    await rows.nth(0).locator('.fi-ta-record-checkbox').tap();
    await rows.nth(1).locator('.fi-ta-record-checkbox').tap();
    await expect(page.locator('.fi-ta-record-checkbox:checked')).toHaveCount(2);
    await page.getByRole('button', { name: 'الإجراءات' }).tap();
    await page.getByRole('menuitem', { name: 'نقل إلى السلة' }).tap();
    const confirm = page.locator('.fi-modal-window:visible').getByRole('button', { name: /نقل إلى السلة|تأكيد/ });
    await afterLivewire(page, () => confirm.tap(), 'callMountedAction');
    await expect.poll(() => app.sql(`SELECT count(*) FROM posts WHERE id IN (${first}, ${second}) AND deleted_at IS NOT NULL`)).toBe('2');

    // Tapping a row's title opens the editor.
    const third = await createPost(page, 'خبر', 'خبر ثالث');
    await page.goto(panel('/posts'));
    await page.locator('.fi-ta-cell-title .fi-ta-col').first().tap();
    await page.waitForURL(new RegExp(`/posts/${third}/edit`));
});

for (const scheme of ['light', 'dark'] as const) {
    test(`the media library at 360 px, ${scheme}: the picture fills its card, the name keeps its extension, the size reads «KB» last, the actions stay inside`, async ({ page, app, adminPassword }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'a-very-long-file-name-from-the-camera-roll.png', 'صورة');
        await uploadImage(page, 'short.png', 'صورة ثانية', [900, 600]);   // other pixels: the library keeps one copy of a file

        await page.goto(panel('/media-assets'));
        expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(scheme === 'dark');
        const cards = page.locator('.fi-ta-record');
        await expect(cards).toHaveCount(2);
        expect(await overflow(page), 'the cards run past the screen').toBeLessThanOrEqual(0);

        for (const card of await cards.all()) {
            const c = await box(card);
            const picture = await box(card.locator('.fi-ta-image img'));
            expect(picture.width, 'the picture fills the card').toBeGreaterThanOrEqual(c.width - 2 * 12 - 1);
            for (const action of await card.locator('.fi-ta-actions > *').all()) {
                const a = await box(action);
                expect(a.x, 'an action starts inside the card').toBeGreaterThanOrEqual(c.x);
                expect(a.x + a.width, 'an action ends inside the card').toBeLessThanOrEqual(c.x + c.width);
            }
        }

        // Cut in its middle, never in its extension: «a-very-long-fil…-camera-roll.png».
        const long = cards.filter({ hasText: 'a-very-long' });
        await expect(long.locator('.fi-ta-text-item').first()).toHaveText(/a-very-long[^…\s]*…[^…\s]*roll\.png/);
        await expect(long).not.toContainText('a-very-long-file-name-from-the-camera-roll.png');
        // The size's figures come before «KB» on screen (a left-to-right run inside the Arabic card).
        const size = long.locator('.fi-ta-text-item', { hasText: /KB|B$/ });
        const [number, unit] = await size.evaluate((e) => {
            const range = document.createRange();
            const text = e.querySelector('span, div')?.firstChild ?? e.firstChild!;
            const at = (needle: RegExp) => {
                const i = text.textContent!.search(needle);
                range.setStart(text, i);
                range.setEnd(text, i + 1);
                return range.getBoundingClientRect().x;
            };
            return [at(/\d/), at(/[KMG]?B/)];
        });
        expect(number, 'the figures are left of «KB»').toBeLessThan(unit);
    });
}
