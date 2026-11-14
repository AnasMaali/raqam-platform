import { expect, test, type Page } from '@playwright/test';

import { panel, panelRoutes, psql, screenName } from './qa-env';

/*
 * Every screen of the panel on the QA database (raqam_qa, raqam:demo with 2000 posts), on a computer and on a 360 px
 * phone: no server error, no error in the console, no English text the editor reads, nothing that sticks out of the
 * screen sideways (RTL). Every list, create, edit and view screen and every settings page is opened, from the panel's
 * own route list; an edit or view screen opens a real record (the post editor one of every kind).
 */

/** Routes that are not screens: the sign-in steps and the session's JSON answer. */
const NOT_SCREENS = new Set(['/login', '/login/code', '/session/status']);

/** The table of a resource whose name is not its address. */
const TABLES: Record<string, string> = { 'audit-logs': 'audit_log', synonyms: 'synonym_groups' };

/** The first row of a table by its primary key (a template's is its `key`), as the panel's address names it. */
function firstKey(table: string): string {
    const column = psql(`SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) WHERE i.indrelid = '${table}'::regclass AND i.indisprimary`);
    return psql(`SELECT ${column} FROM ${table} ORDER BY ${column} LIMIT 1`);
}

/** The screens to open: each route, with a record of its own for `{record}` (one post of each kind). */
function screens(): string[] {
    const out: string[] = [];
    for (const route of panelRoutes()) {
        if (NOT_SCREENS.has(route)) continue;
        if (!route.includes('{')) {
            out.push(route);
            continue;
        }
        const resource = route.split('/')[1];
        const ids =
            resource === 'posts'
                ? psql("SELECT DISTINCT ON (kind_code) id FROM posts WHERE deleted_at IS NULL ORDER BY kind_code, id").split('\n')
                : [firstKey(TABLES[resource] ?? resource.replaceAll('-', '_'))];
        for (const id of ids.filter(Boolean)) out.push(route.replace('{record}', id));
        if (ids.filter(Boolean).length === 0) out.push(`${route} (no record)`);
    }
    return out;
}

/** Words written in Latin letters on purpose: what is called that way in Arabic too, and names of other things. */
const LATIN_ON_PURPOSE = new Set(
    [
        // Currencies and units.
        'USD', 'EUR', 'GBP', 'JOD', 'EGP', 'SAR', 'AED', 'ILS', 'px', 'KB', 'MB', 'GB',
        // Keys of the keyboard, as they are printed on it.
        'Tab', 'Escape', 'Esc', 'Enter', 'Shift', 'Ctrl', 'Alt',
        // Services, formats and terms the platform names as they are called (the helper texts explain them in Arabic).
        'YouTube', 'Shorts', 'WhatsApp', 'OpenWeather', 'OpenStreetMap', 'Brevo', 'Meilisearch', 'IndexNow', 'Turnstile',
        'Cloudflare', 'Google', 'Bing', 'Facebook', 'Instagram', 'TikTok', 'LinkedIn', 'PDF', 'Excel', 'CSV', 'SVG', 'PNG',
        'JPEG', 'JPG', 'WebP', 'AVIF', 'GIF', 'MP4', 'HTML', 'SEO', 'URL', 'API', 'RSS', 'SMTP', 'HMAC', 'IP', 'Rive', 'JSON',
        'XML', 'UTC', 'GMT', 'https', 'rel', 'sponsored', 'log',
        // A browser and its system, as the sign-in records name them (data).
        'Chrome', 'Firefox', 'Safari', 'Edge', 'Opera', 'Linux', 'Windows', 'macOS', 'Android', 'iOS', 'iPhone', 'iPad',
        // An example of a Rive state's name, which the Rive file writes in Latin letters.
        'explaining',
    ].map((w) => w.toLowerCase()),
);

/** Known, and waiting for a decision: shown in the run's notes, not failed. */
const KNOWN: Record<string, string> = {
    Website: "the social_platforms seed row is named 'Website' (an Arabic name means a change to the seed)",
};

type PageReport = { english: string[]; overflow: string[]; scrolls: boolean };

/** Reads the page as the editor sees it: visible English words, and what sticks out sideways. */
async function read(page: Page): Promise<PageReport> {
    return page.evaluate((onPurpose) => {
        const keep = new Set(onPurpose);
        const english = new Map<string, string>();
        const visible = (el: Element) => el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true });
        // Addresses, e-mails, file names and codes are data, not words.
        const strip = (t: string) =>
            t
                .replace(/https?:\/\/\S*/g, ' ')
                .replace(/\S+@\S+/g, ' ')
                // Settings' names (MAIL_FROM_ADDRESS), file names and addresses (llms.txt, youtu.be, a file cut short
                // with …), types (image/jpeg) and paths.
                .replace(/\b[A-Z][A-Z0-9]*(_[A-Z0-9]+)+\b/g, ' ')
                .replace(/[\w-]+\.[\w.]*(\.\.\.|…)?/g, ' ')
                .replace(/\b\w+\/[\w.+-]+/g, ' ')
                .replace(/\/[\w\-/{}.]*/g, ' ')
                .replace(/\b\w*\d\w*\b/g, ' ');
        const note = (text: string, where: Element) => {
            for (const word of strip(text).match(/[A-Za-z][A-Za-z'’-]*[A-Za-z]/g) ?? []) {
                if (keep.has(word.toLowerCase())) continue;
                if (!english.has(word)) english.set(word, `${where.tagName.toLowerCase()}: ${text.trim().replace(/\s+/g, ' ').slice(0, 80)}`);
            }
        };
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const parent = node.parentElement;
            if (!parent || !node.textContent?.trim()) continue;
            if (parent.closest('script, style, noscript, template, code, pre, [contenteditable="true"], .ProseMirror, [data-latin-ok]')) continue;
            if (!visible(parent)) continue;
            note(node.textContent, parent);
        }
        for (const el of document.querySelectorAll('[placeholder], [aria-label], [title]')) {
            if (!visible(el)) continue;
            for (const attr of ['placeholder', 'aria-label', 'title']) {
                const value = el.getAttribute(attr);
                if (value) note(value, el);
            }
        }

        // Something sticks out sideways: outside the screen, and not inside a part that scrolls or clips on purpose.
        const width = document.documentElement.clientWidth;
        const overflow: string[] = [];
        const clipped = (el: Element) => {
            for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
                const s = getComputedStyle(a);
                if (['auto', 'scroll', 'hidden', 'clip'].includes(s.overflowX) || s.position === 'fixed') return true;
            }
            return false;
        };
        for (const el of document.body.querySelectorAll('*')) {
            if (!visible(el) || getComputedStyle(el).position === 'fixed') continue;
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            if ((r.right > width + 1 || r.left < -1) && !clipped(el)) {
                const parent = el.parentElement?.getBoundingClientRect();
                // Only the outermost: its parent fits.
                if (parent && (parent.right > width + 1 || parent.left < -1)) continue;
                const name = `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}.${[...el.classList].slice(0, 3).join('.')}`;
                overflow.push(`${name} (${Math.round(r.left)} to ${Math.round(r.right)} of ${width} px): ${(el.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 50)}`);
            }
        }
        return { english: [...english].map(([w, at]) => `${w}  [${at}]`), overflow: overflow.slice(0, 10), scrolls: document.documentElement.scrollWidth > width + 1 };
    }, [...LATIN_ON_PURPOSE]);
}

for (const screen of screens()) {
    test(`panel ${screen}`, async ({ page }) => {
        test.skip(screen.endsWith('(no record)'), 'the QA database has no record for this screen');
        const problems: string[] = [];
        page.on('console', (m) => m.type() === 'error' && problems.push(`console: ${m.text().slice(0, 200)}`));
        page.on('pageerror', (e) => problems.push(`script error: ${e.message.slice(0, 200)}`));
        page.on('response', (r) => r.status() >= 500 && problems.push(`${r.status()} from ${screenName(r.url())}`));

        const answer = await page.goto(panel(screen === '/' ? '' : screen), { waitUntil: 'load' });
        await page.waitForLoadState('networkidle', { timeout: 10_000 }).catch(() => undefined);
        expect(answer?.status(), 'the screen opens').toBeLessThan(400);
        // Named without the panel's own part, so a failure never prints the panel's address.
        expect(screenName(page.url()), 'not sent back to the sign-in').not.toMatch(/^\/login(\/|$)/);

        const seen = await read(page);
        if (seen.scrolls) problems.push('the page scrolls sideways');
        for (const finding of seen.english) {
            const word = finding.split('  ')[0];
            if (KNOWN[word]) test.info().annotations.push({ type: 'known', description: `«${word}»: ${KNOWN[word]}` });
            else problems.push(`English: ${finding}`);
        }
        problems.push(...seen.overflow.map((o) => `sticks out: ${o}`));
        expect(problems, `${screen} on ${test.info().project.name}`).toEqual([]);
    });
}
