import { expect, test, type BrowserContext, type Page } from '@playwright/test';

import { MEDIA_URL, SITE_URL, load, type SiteMap } from './site-env';

/*
 * Every page of the map opened in a browser, as a visitor does, down to its end: no error in the console or in a
 * script, no violation of the content security policy (the browser's event and its console line), no request of the
 * site or of the platform's media that fails, no picture that does not draw, no id twice on one page (a label, a
 * skip link or an aria reference would find the wrong element). The pages are shared between SHARDS tests, which run
 * side by side; each lists every problem of its pages.
 */

const SHARDS = 16;
const SITE = new URL(SITE_URL).origin;
const MEDIA = new URL(MEDIA_URL).origin;
const name = (url: string) => decodeURI(new URL(url).origin === SITE ? new URL(url).pathname + new URL(url).search : url);

/** What the site asks of other hosts stays on the machine: a picture, or an empty page, answered here. */
async function standIns(context: BrowserContext, outside: Map<string, number>) {
    await context.route(
        (url) => url.origin !== SITE && url.origin !== MEDIA && /^https?:$/.test(url.protocol),
        (route) => {
            const host = new URL(route.request().url()).host;
            outside.set(host, (outside.get(host) ?? 0) + 1);
            return route.request().resourceType() === 'image'
                ? route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="360"><rect width="480" height="360" fill="#124fff"/></svg>' })
                : route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>stand-in</title>' });
        },
    );
}

/** Problems of the page now open, gathered while it loads and runs. */
async function watch(page: Page) {
    const state = { url: '', problems: [] as string[] };
    const note = (text: string) => state.problems.push(`${name(state.url)}: ${text}`);
    await page.exposeBinding('__cspViolation', (_s, text: string) => note(`content security policy: ${text}`));
    await page.addInitScript(() =>
        document.addEventListener('securitypolicyviolation', (e) =>
            (window as unknown as { __cspViolation: (t: string) => void }).__cspViolation(`${e.effectiveDirective} blocked ${e.blockedURI}`),
        ),
    );
    page.on('console', (m) => {
        if (m.type() === 'error') note(`console: ${m.text().slice(0, 200)}`);
        else if (/content security policy/i.test(m.text())) note(`console: ${m.text().slice(0, 200)}`);
    });
    page.on('pageerror', (e) => note(`script error: ${e.message.slice(0, 200)}`));
    page.on('response', (r) => {
        const origin = new URL(r.url()).origin;
        if (r.status() >= 400 && (origin === SITE || origin === MEDIA)) note(`${r.status()} from ${name(r.url())}`);
    });
    page.on('requestfailed', (r) => {
        // A request the browser drops when the page moves on (a prefetch, a picture not needed any more) is not a failure.
        if (!/ERR_ABORTED/.test(r.failure()?.errorText ?? '')) note(`${r.failure()?.errorText} for ${name(r.url())}`);
    });
    return state;
}

/** Down the page to its end, so every picture that waits to be seen loads, and back up. */
async function scrollThrough(page: Page) {
    await page.evaluate(async () => {
        for (let y = 0; y < document.body.scrollHeight; y += 900) {
            window.scrollTo(0, y);
            await new Promise((r) => setTimeout(r, 40));
        }
    });
    await page.waitForLoadState('networkidle', { timeout: 8_000 }).catch(() => undefined);
}

const pagesOf = (shard: number) => load<SiteMap>('map.json').pages.filter((p) => p.html && p.kind !== 'file').filter((_, i) => i % SHARDS === shard);

for (let shard = 0; shard < SHARDS; shard++) {
    test(`pages in a browser, part ${shard + 1} of ${SHARDS}`, async ({ page, context }) => {
        test.setTimeout(60 * 60_000);
        const outside = new Map<string, number>();
        await standIns(context, outside);
        const state = await watch(page);
        const list = pagesOf(shard);
        for (const { url } of list) {
            state.url = url;
            const answer = await page.goto(url, { waitUntil: 'load' }).catch((e: Error) => {
                state.problems.push(`${name(url)}: ${e.message.split('\n')[0]}`);
                return null;
            });
            if (!answer) continue;
            if (answer.status() !== 200) state.problems.push(`${name(url)}: answers ${answer.status()}`);
            await scrollThrough(page);
            const found = await page.evaluate(() => {
                const ids = new Map<string, number>();
                for (const el of document.querySelectorAll('[id]')) ids.set(el.id, (ids.get(el.id) ?? 0) + 1);
                const twice = [...ids].filter(([, n]) => n > 1).map(([id, n]) => `id «${id}» ${n} times`);
                // A picture the browser tried and could not draw (one still waiting to be seen is not complete yet).
                const broken = [...document.images].filter((img) => img.complete && img.currentSrc && img.naturalWidth === 0).map((img) => `picture does not draw: ${img.currentSrc}`);
                const h1 = document.querySelectorAll('h1').length;
                return [...twice, ...broken, ...(h1 === 1 ? [] : [`${h1} h1 in the page`])];
            });
            state.problems.push(...found.map((f) => `${name(url)}: ${f}`));
        }
        if (outside.size > 0) test.info().annotations.push({ type: 'answered here instead of another host', description: [...outside].map(([h, n]) => `${h} ${n}`).join(', ') });
        expect(list.length, 'this part has pages').toBeGreaterThan(0);
        expect(state.problems).toEqual([]);
    });
}
