import { expect, test } from '@playwright/test';

import { NOINDEX_PAGES, SITE_URL, load, type MappedPage, type SiteMap } from './site-env';

/*
 * What the map of the QA website found, judged: every address answers, no link inside the site is broken, every
 * picture and video file arrives, and every page is ready for search engines (decision 23): a title and a description
 * of its own, a canonical address that is the page itself, noindex only where it belongs, structured data that reads
 * and has what its kind needs, one h1.
 */

const SITE = new URL(SITE_URL).origin;
const map = () => load<SiteMap>('map.json');
const name = (url: string) => {
    const u = new URL(url, SITE);
    return decodeURI(u.origin === SITE ? u.pathname + u.search : u.href);
};
const pages = () => map().pages.filter((p): p is MappedPage & { html: NonNullable<MappedPage['html']> } => !!p.html);
const noindex = (p: { html: { robots: string | null } }) => /noindex/i.test(p.html.robots ?? '');
/** A list narrowed by a filter (?season=2): noindex, and its canonical is the list itself (5.9, 8.10). */
const filtered = (p: MappedPage) => new URL(p.url).search !== '' && !new URL(p.url).pathname.startsWith('/search');
const path = (url: string) => {
    const u = new URL(url, SITE);
    return decodeURI(u.pathname.replace(/\/$/, '') || '/');
};

test('every address answers: no server error, no broken link inside the site, every picture and video file arrives', () => {
    const { pages, links, media } = map();
    const problems = [
        ...pages.filter((p) => p.status === 0 || p.status >= 500).map((p) => `${p.status || 'no answer'} ${name(p.url)} (${p.contentType})`),
        // The sitemap lists the page itself, never an address that moved.
        ...pages.filter((p) => p.inSitemap && p.status !== 200).map((p) => `the sitemap lists ${name(p.url)}, which answers ${p.status}${p.location ? ` to ${name(p.location)}` : ''}`),
        ...links.map((l) => `${name(l.from)} links to ${name(l.url)}, which answers ${l.status}`),
        ...media
            .filter((m) => m.status !== 200 || !/^(image|video|audio)\//.test(m.contentType))
            .map((m) => `${name(m.from)} shows ${m.url}, which answers ${m.status} ${m.contentType}`),
    ];
    expect(problems).toEqual([]);
});

test('every page has a title and a description, and no two indexed pages share one', () => {
    const all = pages();
    const problems = [
        ...all.filter((p) => !p.html.title?.trim()).map((p) => `${name(p.url)}: no title`),
        ...all.filter((p) => !p.html.description?.trim()).map((p) => `${name(p.url)}: no description`),
    ];
    const notes: string[] = [];
    for (const field of ['title', 'description'] as const) {
        const groups = new Map<string, typeof all>();
        for (const p of all.filter((p) => !noindex(p))) {
            const value = p.html[field]?.trim();
            if (value) groups.set(value, [...(groups.get(value) ?? []), p]);
        }
        for (const [value, group] of groups) {
            if (group.length < 2) continue;
            // Between posts only, what the editor wrote twice (the demo data repeats some headlines and summaries): a
            // post's title is its headline, its description its summary. That is the data's, and only noted. Any
            // other pair is two pages the site itself names alike.
            const posts = group.every((p) => p.kind.startsWith('post: '));
            const written = posts && (field === 'description' || group.every((p) => p.html.h1Text === group[0].html.h1Text));
            const line = `${field} «${value.slice(0, 70)}» on ${group.length} pages: ${group.slice(0, 4).map((p) => name(p.url)).join(', ')}`;
            if (written) notes.push(line);
            else problems.push(line);
        }
    }
    if (notes.length > 0) test.info().annotations.push({ type: 'written twice in the data', description: `${notes.length} headlines or summaries` });
    expect(problems).toEqual([]);
});

test('the canonical address is the page itself (a filtered list: the list)', () => {
    const all = pages();
    const indexed = new Set(all.filter((p) => !noindex(p)).map((p) => path(p.url)));
    const problems: string[] = [];
    for (const p of all) {
        const canonical = p.html.canonical;
        if (!canonical) {
            problems.push(`${name(p.url)}: no canonical`);
            continue;
        }
        const url = new URL(canonical, p.url);
        if (url.origin !== SITE || url.search) problems.push(`${name(p.url)}: canonical ${canonical}`);
        else if (filtered(p) && noindex(p)) {
            if (!indexed.has(path(url.href))) problems.push(`${name(p.url)}: canonical ${name(url.href)} is not an indexed page`);
        } else if (path(url.href) !== path(p.url)) problems.push(`${name(p.url)}: canonical ${name(url.href)}`);
    }
    expect(problems).toEqual([]);
});

test('noindex only where it belongs: a search, the newsletter forms, a filtered list; never a page of the sitemap', () => {
    const problems: string[] = [];
    for (const p of pages()) {
        const meant = NOINDEX_PAGES.includes(path(p.url)) || filtered(p);
        if (noindex(p) && !meant) problems.push(`${name(p.url)} is noindex`);
        if (!noindex(p) && meant) problems.push(`${name(p.url)} is indexed`);
        if (noindex(p) && p.inSitemap) problems.push(`the sitemap lists ${name(p.url)}, which is noindex`);
    }
    expect(problems).toEqual([]);
});

type Node = Record<string, unknown>;
const ARTICLE = ['headline', 'description', 'image', 'datePublished', 'dateModified', 'author', 'publisher', 'url', 'mainEntityOfPage'];
const PAGE = ['name', 'url', 'inLanguage'];
/** What each kind of node needs, after Google's and schema.org's basics. A kind with no line here fails: add its line. */
const NEEDS: Record<string, string[]> = {
    NewsArticle: ARTICLE,
    OpinionNewsArticle: ARTICLE,
    ReportageNewsArticle: ARTICLE,
    AnalysisNewsArticle: ARTICLE,
    Article: ARTICLE,
    VideoObject: ['name', 'description', 'thumbnailUrl', 'uploadDate'],
    BreadcrumbList: ['itemListElement'],
    NewsMediaOrganization: ['name', 'url', 'logo'],
    WebSite: ['name', 'url'],
    WebPage: PAGE,
    AboutPage: PAGE,
    ContactPage: PAGE,
    CollectionPage: [...PAGE, 'mainEntity'],
    FAQPage: [...PAGE, 'mainEntity'],
    ProfilePage: [...PAGE, 'mainEntity'],
    Person: ['name', 'url'],
    Service: ['name', 'description', 'provider', 'url'],
    Place: ['name', 'geo'],
};
/** What each kind of page carries at least. */
const PAGE_NEEDS: [RegExp, string[]][] = [
    [/^home$/, ['WebSite', 'NewsMediaOrganization']],
    [/^post: /, ['BreadcrumbList']],
    [/^(section: |category|tag|video library|awareness character)/, ['CollectionPage', 'BreadcrumbList']],
    [/^(author|guest)/, ['ProfilePage', 'Person', 'BreadcrumbList']],
    [/^video$/, ['VideoObject', 'BreadcrumbList']],
    [/^faq$/, ['FAQPage']],
    [/^service$/, ['Service', 'BreadcrumbList']],
];
const ARTICLES = /Article$/;

test('the structured data reads, and has what its kind needs', () => {
    const problems: string[] = [];
    for (const p of pages()) {
        if (p.kind === 'file') continue;
        problems.push(...p.html.jsonLdErrors.map((e) => `${name(p.url)}: does not read: ${e}`));
        const nodes = p.html.jsonLd.flatMap((x) => ((x as Node)['@graph'] as Node[] | undefined) ?? [x as Node]);
        if (noindex(p)) continue;
        if (nodes.length === 0) {
            problems.push(`${name(p.url)} (${p.kind}): no structured data`);
            continue;
        }
        const types = nodes.map((n) => String(n['@type']));
        for (const n of nodes) {
            const type = String(n['@type']);
            const needs = NEEDS[type];
            if (!needs) {
                problems.push(`${name(p.url)}: no rule for ${type} in NEEDS`);
                continue;
            }
            const missing = needs.filter((k) => n[k] === undefined || n[k] === null || n[k] === '' || (Array.isArray(n[k]) && (n[k] as unknown[]).length === 0));
            if (missing.length > 0) problems.push(`${name(p.url)}: ${type} without ${missing.join(', ')}`);
            // Every address it gives is on the site (or a picture's), never the API's.
            for (const key of ['url', 'mainEntityOfPage']) {
                const value = typeof n[key] === 'object' && n[key] ? (n[key] as Node)['@id'] : n[key];
                if (typeof value === 'string' && new URL(value, SITE).origin !== SITE) problems.push(`${name(p.url)}: ${type}.${key} is ${value}`);
            }
            if (type === 'BreadcrumbList') {
                const items = (n.itemListElement as Node[]) ?? [];
                items.forEach((item, i) => {
                    if (item.position !== i + 1 || !item.name || typeof item.item !== 'string' || new URL(item.item).origin !== SITE) {
                        problems.push(`${name(p.url)}: breadcrumb ${i + 1} is ${JSON.stringify(item).slice(0, 120)}`);
                    }
                });
                if (items.length > 0 && path(String(items.at(-1)!.item)) !== path(p.url) && !/page n/.test(p.kind)) {
                    problems.push(`${name(p.url)}: the breadcrumb ends at ${name(String(items.at(-1)!.item))}`);
                }
            }
            // The page's own node names the page's own address.
            if ((ARTICLES.test(type) || /Page$/.test(type)) && typeof n.url === 'string' && path(n.url) !== path(p.url)) {
                problems.push(`${name(p.url)}: ${type}.url is ${name(n.url)}`);
            }
        }
        for (const [kind, wanted] of PAGE_NEEDS) {
            if (!kind.test(p.kind)) continue;
            for (const t of wanted) if (!types.includes(t)) problems.push(`${name(p.url)} (${p.kind}): no ${t}`);
        }
        if (p.kind.startsWith('post: ') && !types.some((t) => ARTICLES.test(t))) problems.push(`${name(p.url)}: no article node`);
    }
    expect(problems).toEqual([]);
});

test('one h1 a page', () => {
    expect(pages().filter((p) => p.kind !== 'file' && p.html.h1 !== 1).map((p) => `${name(p.url)}: ${p.html.h1} h1`)).toEqual([]);
});

test('every page of the site is in the sitemap, or noindex, or a page of a list', () => {
    // A page a search engine may index but the sitemap leaves out is found late, or never if no page links to it.
    const out = pages().filter((p) => p.kind !== 'file' && !p.inSitemap && !noindex(p) && !/page n$/.test(p.kind));
    expect(out.map((p) => `${name(p.url)} (${p.kind}), linked from ${name(p.from)}`)).toEqual([]);
});

test('every page is linked from another page of the site', () => {
    // A page no other page links to is reached only through the sitemap, or not at all (a visitor never finds it).
    const alone = pages().filter((p) => p.kind !== 'file' && p.kind !== 'home' && p.inbound === 0 && !noindex(p));
    expect(alone.map((p) => `${name(p.url)} (${p.kind}${p.inSitemap ? ', in the sitemap' : ''})`)).toEqual([]);
});

test('an address with nothing at it answers 404 at once, in the site\'s frame; a post under a wrong slug moves to its own', async () => {
    const ask = (path: string) => fetch(`${SITE}${encodeURI(path)}`, { redirect: 'manual', signal: AbortSignal.timeout(10_000) }).catch((e: Error) => e);
    const problems: string[] = [];
    for (const path of ['/news/999999999/لا-يوجد', '/category/لا-يوجد-تصنيف', '/صفحة-لا-توجد', '/video/999999999', '/currencies/xyz']) {
        const answer = await ask(path);
        if (answer instanceof Error) problems.push(`${path}: no answer in 10 s (${answer.name})`);
        else if (answer.status !== 404) problems.push(`${path}: ${answer.status}`);
        else if (!/<header[\s>]/.test(await answer.text())) problems.push(`${path}: 404 without the site's frame`);
    }
    const post = map().pages.find((p) => p.kind === 'post: news' && p.status === 200);
    expect(post, 'a news post on the map').toBeDefined();
    const own = new URL(post!.url).pathname;
    const moved = await ask(own.replace(/\/[^/]+$/, '/عنوان-قديم'));
    if (moved instanceof Error) problems.push(`a wrong slug: no answer in 10 s`);
    else if (moved.status !== 301 || new URL(moved.headers.get('location') ?? '', SITE).pathname !== own) problems.push(`a wrong slug: ${moved.status} to ${moved.headers.get('location')}`);
    expect(problems).toEqual([]);
});
