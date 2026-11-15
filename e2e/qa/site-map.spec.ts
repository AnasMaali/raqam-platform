import { readdirSync } from 'node:fs';
import { join, relative } from 'node:path';

import { expect, test } from '@playwright/test';

import { MEDIA_URL, SITE_URL, kindOf, save, type MappedPage, type SiteMap } from './site-env';

/*
 * The map of the QA website: every address of its sitemaps, and every address of the site any page links to, as a
 * search engine reaches them (plain requests, the HTML the server sends). Each page is read once, with what the checks
 * after it need: its answer, its head (title, description, canonical, robots, structured data), its h1s, its links and
 * its pictures. Nothing is judged here: site-checks.spec.ts reads the map, the browser pass opens its pages.
 */

const SITE = new URL(SITE_URL).origin;
const MEDIA = new URL(MEDIA_URL).origin;
/** More than the site can have with the QA data (about 3000); reaching it means links that never end (a calendar). */
const MOST_PAGES = 12_000;
const AT_ONCE = 6;

const entities: Record<string, string> = { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: ' ' };
const decode = (text: string) =>
    text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (all, name: string) =>
        name[0] === '#' ? String.fromCodePoint(name[1].toLowerCase() === 'x' ? parseInt(name.slice(2), 16) : Number(name.slice(1))) : (entities[name] ?? all),
    );
const attribute = (tag: string, name: string) => {
    const m = new RegExp(`\\s${name}=(?:"([^"]*)"|'([^']*)')`, 'i').exec(tag);
    return m ? decode(m[1] ?? m[2]) : null;
};
const tags = (html: string, name: string) => html.match(new RegExp(`<${name}\\b[^>]*>`, 'gi')) ?? [];
const meta = (html: string, name: string) => {
    const tag = tags(html, 'meta').find((t) => attribute(t, 'name')?.toLowerCase() === name);
    return tag ? attribute(tag, 'content') : null;
};

/** The head and body as a search engine reads them. Scripts are taken out first (the page's data repeats its markup). */
function readHtml(html: string, url: string): NonNullable<MappedPage['html']> & { links: string[]; media: string[] } {
    const jsonLd: unknown[] = [];
    const jsonLdErrors: string[] = [];
    for (const m of html.matchAll(/<script\b[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi)) {
        try {
            jsonLd.push(JSON.parse(m[1]));
        } catch (e) {
            jsonLdErrors.push(`${(e as Error).message}: ${m[1].slice(0, 80)}`);
        }
    }
    const body = html.replace(/<script\b[\s\S]*?<\/script>/gi, '').replace(/<template\b[\s\S]*?<\/template>/gi, '');
    const title = /<title\b[^>]*>([\s\S]*?)<\/title>/i.exec(body)?.[1];
    const canonical = tags(body, 'link').find((t) => attribute(t, 'rel') === 'canonical');

    const links = tags(body, 'a').map((t) => attribute(t, 'href')).filter((h): h is string => !!h);
    const media: string[] = [];
    for (const name of ['img', 'source', 'video', 'audio']) {
        for (const tag of tags(body, name)) {
            for (const attr of ['src', 'poster']) {
                const value = attribute(tag, attr);
                if (value) media.push(value);
            }
            const set = attribute(tag, 'srcset');
            if (set) media.push(...set.split(',').map((c) => c.trim().split(/\s+/)[0]).filter(Boolean));
        }
    }

    return {
        title: title ? decode(title).trim() : null,
        description: meta(body, 'description'),
        canonical: canonical ? attribute(canonical, 'href') : null,
        robots: meta(body, 'robots'),
        h1: tags(body, 'h1').length,
        h1Text: (() => {
            const m = /<h1\b[^>]*>([\s\S]*?)<\/h1>/i.exec(body);
            return m ? decode(m[1].replace(/<[^>]+>/g, '')).replace(/\s+/g, ' ').trim() : null;
        })(),
        jsonLd,
        jsonLdErrors,
        links: links.map((h) => {
            try {
                return new URL(h, url).href;
            } catch {
                return h;
            }
        }),
        media: media.map((m) => new URL(m, url).href),
    };
}

/** The site's pages that have an address of their own (no [part] in it), from its source: /news, /sitemap, /search… */
function ownPages(): string[] {
    const root = new URL('../../frontend/src/app/(pages)/', import.meta.url).pathname;
    const found: string[] = ['/'];
    const walk = (dir: string) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            if (entry.isDirectory() && !entry.name.includes('[')) walk(join(dir, entry.name));
            else if (entry.name === 'page.tsx') found.push(`/${relative(root, dir)}`);
        }
    };
    walk(root);
    return found;
}

const locs = (xml: string) => [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => decode(m[1]).trim());

/** One address the same way every time: without its #part, and with its path in one spelling. */
function normal(address: string): string {
    const url = new URL(address);
    url.hash = '';
    try {
        url.pathname = encodeURI(decodeURI(url.pathname));
    } catch {
        // A broken escape stays as it is: the request shows what it answers.
    }
    return url.href;
}

test('the map of the site: every address of the sitemaps and every link between pages', async () => {
    test.setTimeout(60 * 60_000);
    const index = await fetch(`${SITE}/sitemap.xml`);
    expect(index.status, 'the sitemap index answers').toBe(200);

    const map: SiteMap = { pages: [], links: [], media: [], external: {}, platform: 0, actions: [] };
    const queued = new Map<string, { from: string; inSitemap: boolean }>();
    const queue: string[] = [];
    const enqueue = (address: string, from: string, inSitemap = false) => {
        const key = normal(address);
        const seen = queued.get(key);
        if (seen) {
            seen.inSitemap ||= inSitemap;
            return key;
        }
        queued.set(key, { from, inSitemap });
        queue.push(key);
        return key;
    };

    // The sitemaps first: each address they list is a page, and is said so.
    for (const file of locs(await index.text())) {
        const part = await fetch(file);
        expect(part.status, `${new URL(file).pathname} answers`).toBe(200);
        for (const loc of locs(await part.text())) enqueue(loc, 'sitemap', true);
    }
    expect(queue.length, 'the sitemaps list pages').toBeGreaterThan(100);
    // And the site's own pages, so one that nothing links to and the sitemap leaves out is found too.
    for (const own of ownPages()) enqueue(`${SITE}${encodeURI(own)}`, 'routes');

    const media = new Map<string, string>();
    const answered = new Map<string, number>();
    const linksTo: { url: string; from: string }[] = [];

    const visit = async (address: string) => {
        const { from, inSitemap } = queued.get(address)!;
        let res: Response;
        try {
            res = await fetch(address, { redirect: 'manual', headers: { 'user-agent': 'raqam-qa-map' } });
        } catch (e) {
            map.pages.push({ url: address, status: 0, kind: kindOf(address), inSitemap, from, inbound: 0, contentType: `no answer: ${(e as Error).message}` });
            answered.set(address, 0);
            return;
        }
        const contentType = res.headers.get('content-type') ?? '';
        const page: MappedPage = { url: address, status: res.status, kind: kindOf(address), inSitemap, from, inbound: 0, contentType };
        answered.set(address, res.status);
        if (res.status >= 300 && res.status < 400) {
            const location = res.headers.get('location');
            if (location) {
                page.location = new URL(location, address).href;
                if (new URL(page.location).origin === SITE) enqueue(page.location, address);
            }
        } else if (res.status === 200 && contentType.includes('text/html')) {
            const read = readHtml(await res.text(), address);
            const { links, media: files, ...html } = read;
            page.html = html;
            for (const link of links) {
                if (!/^https?:/.test(link)) continue;
                const url = new URL(link);
                if (url.origin === MEDIA) map.platform++;
                else if (url.origin !== SITE) map.external[url.host] = (map.external[url.host] ?? 0) + 1;
                else if (url.pathname.startsWith('/api/')) map.actions.push(`${url.pathname} on ${address}`);
                else linksTo.push({ url: enqueue(link, address), from: address });
            }
            for (const file of files) {
                const origin = new URL(file).origin;
                if (origin !== SITE && origin !== MEDIA) map.external[new URL(file).host] = (map.external[new URL(file).host] ?? 0) + 1;
                else if (!media.has(file)) media.set(file, address);
            }
        }
        await res.body?.cancel().catch(() => undefined);
        map.pages.push(page);
    };

    // A few requests side by side (the QA API answers with four workers).
    let next = 0;
    const worker = async () => {
        while (next < queue.length && queue.length <= MOST_PAGES) await visit(queue[next++]);
    };
    for (let round = 0; next < queue.length && queue.length <= MOST_PAGES; round++) {
        await Promise.all(Array.from({ length: AT_ONCE }, worker));
    }
    expect(queue.length, 'the links end somewhere').toBeLessThanOrEqual(MOST_PAGES);

    // How many pages link to each, and each link to a page of the site that does not answer with the page itself.
    const inbound = new Map<string, Set<string>>();
    for (const { url, from } of linksTo) if (from !== url) inbound.set(url, (inbound.get(url) ?? new Set()).add(from));
    for (const page of map.pages) page.inbound = inbound.get(page.url)?.size ?? 0;
    const reported = new Set<string>();
    for (const { url, from } of linksTo) {
        const status = answered.get(url) ?? 0;
        if (status !== 200 && !reported.has(`${url} ${from}`)) {
            reported.add(`${url} ${from}`);
            map.links.push({ url, status, from });
        }
    }

    // Every picture and video file, once.
    const files = [...media];
    let at = 0;
    await Promise.all(
        Array.from({ length: AT_ONCE * 2 }, async () => {
            while (at < files.length) {
                const [url, from] = files[at++];
                const res = await fetch(url, { method: 'HEAD' }).catch(() => null);
                map.media.push({ url, status: res?.status ?? 0, contentType: res?.headers.get('content-type') ?? '', from });
            }
        }),
    );

    save('map.json', map);
    const html = map.pages.filter((p) => p.html);
    console.log(`The map: ${map.pages.length} addresses (${html.length} pages), ${map.media.length} picture and video files, ${Object.values(map.external).reduce((a, b) => a + b, 0)} links out of the site to ${Object.keys(map.external).length} hosts.`);
    expect(html.length, 'pages to check').toBeGreaterThan(100);
});
