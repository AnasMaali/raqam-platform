import type { Page } from '@playwright/test';

import { test, expect, signIn } from '../support/fixtures';
import { panel } from '../support/fixtures';

/*
 * The panel's identity (DESIGN.md, "The panel"), as the browser draws it, in the light and the dark theme:
 *   - every text field and select is bordered at 3:1 or more against the control and against what is around it
 *     (WCAG 1.4.11): the automatic accessibility check does not measure borders, so this test does;
 *   - the text is set in Alexandria, served by the panel itself.
 */

type Measured = { field: string; ring: string; inside: number; outside: number };

/** Every enabled field on the page: the colour of its 1px ring and its contrast with the control and the page behind it. */
async function fieldBorders(page: Page): Promise<Measured[]> {
    // A focused field draws its 2px focus ring instead; the resting border is what is measured (after its transition).
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await page.waitForTimeout(200);
    return page.evaluate(() => {
        const canvas = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
        // Any CSS colour (oklch, color-mix, rgba…) as the browser paints it: [r, g, b, a] with a in 0..1.
        const rgba = (color: string): [number, number, number, number] => {
            canvas.clearRect(0, 0, 1, 1);
            canvas.fillStyle = '#000';
            canvas.fillStyle = color;
            canvas.fillRect(0, 0, 1, 1);
            const [r, g, b, a] = canvas.getImageData(0, 0, 1, 1).data;
            return [r, g, b, a / 255];
        };
        const over = (top: number[], below: number[]) => [0, 1, 2].map((i) => top[i] * top[3] + below[i] * (1 - top[3])).concat(1);
        // What is painted behind an element: its own background and its ancestors', composed down to the page.
        const backdrop = (el: Element | null): number[] => {
            const layers: number[][] = [];
            for (let n = el; n; n = n.parentElement) {
                const c = rgba(getComputedStyle(n).backgroundColor);
                if (c[3] > 0) layers.push(c);
                if (c[3] === 1) break;
            }
            return layers.reduceRight((below, top) => over(top, below), [255, 255, 255, 1]);
        };
        const luminance = (c: number[]) => {
            const [r, g, b] = c.slice(0, 3).map((v) => v / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const ratio = (a: number[], b: number[]) => {
            const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
            return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
        };

        return [...document.querySelectorAll('.fi-input-wrp:not(.fi-disabled):not(.fi-invalid)')]
            .filter((el) => (el as HTMLElement).offsetParent !== null)
            .map((el) => {
                // Tailwind's ring is a box-shadow with a 1px spread; its colour is the shadow's.
                const shadows = getComputedStyle(el).boxShadow.split(/,(?![^(]*\))/);
                const ring = shadows.find((s) => /\b0px 0px 0px 1px\b/.test(s) && !/inset/.test(s)) ?? '';
                const color = ring.replace(/\s*(-?\d+(\.\d+)?px\s*){4}\s*(inset)?\s*$/, '').trim();
                const outside = backdrop(el.parentElement);
                const ringOnPage = over(rgba(color), outside);
                const label = el.closest('.fi-fo-field')?.querySelector('label')?.textContent?.trim() ?? el.className;
                if (color === '') return { field: label.slice(0, 60), ring: '(no border)', inside: 0, outside: 0 };
                return { field: label.slice(0, 60), ring: color, inside: ratio(ringOnPage, backdrop(el)), outside: ratio(ringOnPage, outside) };
            });
    });
}

for (const scheme of ['light', 'dark'] as const) {
    test(`every field is bordered at 3:1 or more and the text is Alexandria (${scheme})`, async ({ page, app, adminPassword }) => {
        await page.emulateMedia({ colorScheme: scheme });

        // Signed out: the sign-in page.
        await page.goto(panel('/login'));
        await page.waitForLoadState('networkidle');
        const signInFields = await fieldBorders(page);
        expect(signInFields.length).toBeGreaterThanOrEqual(2);
        for (const f of signInFields) {
            expect.soft(f.inside, `${f.field}: ${f.ring} inside`).toBeGreaterThanOrEqual(3);
            expect.soft(f.outside, `${f.field}: ${f.ring} outside`).toBeGreaterThanOrEqual(3);
        }

        await signIn(page, app, adminPassword);
        for (const path of ['/posts/create', '/settings', '/posts']) {
            await page.goto(panel(path));
            await page.waitForLoadState('networkidle');
            expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(scheme === 'dark');

            const fields = await fieldBorders(page);
            expect(fields.length, path).toBeGreaterThan(0);
            for (const f of fields) {
                expect.soft(f.inside, `${path} ${f.field}: ${f.ring} inside`).toBeGreaterThanOrEqual(3);
                expect.soft(f.outside, `${path} ${f.field}: ${f.ring} outside`).toBeGreaterThanOrEqual(3);
            }

            const font = await page.evaluate(async () => {
                await document.fonts.ready;
                return { family: getComputedStyle(document.body).fontFamily, loaded: document.fonts.check('16px Alexandria', 'رقم') };
            });
            expect(font.family, path).toMatch(/^"?Alexandria"?/);
            expect(font.loaded, path).toBe(true);
        }
    });
}
