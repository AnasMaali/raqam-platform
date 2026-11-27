import { test, expect, panel, signIn } from '../support/fixtures';
import { createPost } from '../support/panel';
import type { Locator } from '@playwright/test';

/*
 * A date and time inside the Arabic panel reads as written: 2026-11-21 21:18, left to right, in Palestine time, digits
 * 0-9. Left to the page's direction its parts swap places («21:18 2026-11-21»). The order is read from the screen: each
 * character where it is drawn, from left to right.
 */

/** The text of an element as the eye reads it from left to right: each character placed by where it is drawn. */
const drawnLeftToRight = (cell: Locator) =>
    cell.evaluate((element) => {
        const chars: { x: number; c: string }[] = [];
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const text = node.textContent ?? '';
            for (let i = 0; i < text.length; i++) {
                const range = document.createRange();
                range.setStart(node, i);
                range.setEnd(node, i + 1);
                const rect = range.getBoundingClientRect();
                if (rect.width > 0) chars.push({ x: rect.left + rect.width / 2, c: text[i] });
            }
        }
        return chars.sort((a, b) => a.x - b.x).map((ch) => ch.c).join('').replace(/[ ‑]/g, (c) => (c === ' ' ? ' ' : '-')).trim();
    });

for (const width of [1440, 360]) {
    test.describe(`at ${width} px`, () => {
        test.use({ viewport: { width, height: 900 } });

        test('a post\'s publish time reads 2026-11-21 21:18 and an audit row\'s time keeps its seconds, not the other way round', async ({ page, app, adminPassword }) => {
            await signIn(page, app, adminPassword);
            const id = await createPost(page, 'خبر', 'خبر منشور');
            // 19:18 UTC is 21:18 in Palestine in November (UTC+2). A draft may hold a planned time; the list shows it.
            app.sql(`UPDATE posts SET published_at = '2026-11-21 19:18:00+00' WHERE id = ${id}`);

            await page.goto(panel('/posts'));
            const published = page.locator('.fi-ta-cell-published-at .raqam-date').first();
            await expect(published).toBeVisible();
            expect(await drawnLeftToRight(published)).toBe('2026-11-21 21:18');

            // The audit log, a security record: its time with seconds, in the same order as the database gives it in Palestine time.
            const expected = app.sql(`SELECT to_char(occurred_at AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD HH24:MI:SS') FROM audit_log ORDER BY occurred_at DESC, id DESC LIMIT 1`).trim();
            await page.goto(panel('/audit-logs'));
            const occurred = page.locator('.fi-ta-cell-occurred-at .raqam-date').first();
            await expect(occurred).toBeVisible();
            const read = await drawnLeftToRight(occurred);
            expect(read).toMatch(/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/);
            expect(read).toBe(expected);
            // One line: never cut at a hyphen or the space.
            const lines = await occurred.evaluate((e) => e.getClientRects().length);
            expect(lines).toBe(1);
        });
    });
}
