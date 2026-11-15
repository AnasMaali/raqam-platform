import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { MEDIA_URL, SITE_URL, load, type SiteMap } from './site-env';

/*
 * axe-core on one page of every kind the map found (the home page, an article of each section, a list and its second
 * page, a filtered list, a writer, a video, a currency, a city, a service, each page of its own…), in the light theme
 * and the dark one, on a computer and a phone. Not one violation that axe rates serious or critical.
 */

const SITE = new URL(SITE_URL).origin;
const MEDIA = new URL(MEDIA_URL).origin;
const name = (url: string) => decodeURI(new URL(url).pathname + new URL(url).search);

/** The first page of each kind, in the map's order (the sitemap's first, then what links found). */
function samples() {
    const seen = new Map<string, string>();
    for (const p of load<SiteMap>('map.json').pages) {
        if (p.html && p.kind !== 'file' && !seen.has(p.kind)) seen.set(p.kind, p.url);
    }
    return [...seen];
}

const sizes = { computer: { width: 1440, height: 900 }, phone: { width: 375, height: 812 } };

for (const theme of ['light', 'dark'] as const) {
    for (const [size, viewport] of Object.entries(sizes)) {
        test(`accessibility of every kind of page: ${theme} theme, ${size}`, async ({ page, context }) => {
            test.setTimeout(30 * 60_000);
            await page.setViewportSize(viewport);
            await page.emulateMedia({ colorScheme: theme });
            // The reader's own choice, as the site keeps it, so the page is drawn in that theme from the first paint.
            await page.addInitScript((value) => window.localStorage.setItem('raqam-theme', value), theme);
            await context.route(
                (url) => url.origin !== SITE && url.origin !== MEDIA && /^https?:$/.test(url.protocol),
                (route) =>
                    route.request().resourceType() === 'image'
                        ? route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="360"><rect width="480" height="360" fill="#124fff"/></svg>' })
                        : route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>stand-in</title>' }),
            );

            const problems: string[] = [];
            const list = samples();
            for (const [kind, url] of list) {
                await page.goto(url, { waitUntil: 'load' });
                await page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => undefined);
                expect(await page.locator('html').getAttribute('data-theme'), `${kind} is drawn in the ${theme} theme`).toBe(theme);
                const { violations } = await new AxeBuilder({ page }).analyze();
                for (const v of violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')) {
                    const where = v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ');
                    problems.push(`${kind} (${name(url)}): ${v.impact} ${v.id}: ${v.help} [${v.nodes.length}× ${where}]`);
                }
            }
            expect(list.length, 'kinds of pages').toBeGreaterThan(20);
            expect(problems).toEqual([]);
        });
    }
}
