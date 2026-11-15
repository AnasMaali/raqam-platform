import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

import { QA_URL, qaSetting } from './qa-env';

/*
 * The QA website as the site checks see it (docs/qa/README.md): built and served by `backend/scripts/qa.sh site`, on
 * SITE_URL of backend/.env.qa, reading the QA API. What the map of the site finds is kept in e2e/.qa/site/ (git-ignored)
 * for the checks after it.
 */

export const SITE_URL = qaSetting('SITE_URL');
/** Where the platform's pictures and videos come from: the QA API's own address. */
export const MEDIA_URL = QA_URL;

const RESULTS = new URL('../.qa/site/', import.meta.url).pathname;
export const result = (name: string) => join(RESULTS, name);

export function save(name: string, value: unknown) {
    mkdirSync(RESULTS, { recursive: true });
    writeFileSync(result(name), JSON.stringify(value, null, 1));
}

export function load<T>(name: string): T {
    const file = result(name);
    if (!existsSync(file)) throw new Error(`${name} is missing: the map of the site (project site-map) runs first.`);
    return JSON.parse(readFileSync(file, 'utf8')) as T;
}

/** The website server's output (`qa.sh site start` writes it there). */
export const SITE_LOG = join(dirname(qaSetting('LARAVEL_STORAGE_PATH')), 'site.log');

/** One page of the site, as the map found it. */
export type MappedPage = {
    url: string;
    status: number;
    kind: string;
    inSitemap: boolean;
    /** The first page that links here ("sitemap" for an address only the sitemap lists, "routes" for the site's own). */
    from: string;
    /** How many pages link here. */
    inbound: number;
    contentType: string;
    location?: string;
    html?: {
        title: string | null;
        description: string | null;
        canonical: string | null;
        robots: string | null;
        h1: number;
        /** The first h1's text: two pages with the same title and the same h1 hold the same headline (the data's). */
        h1Text: string | null;
        jsonLd: unknown[];
        jsonLdErrors: string[];
    };
};

export type SiteMap = {
    pages: MappedPage[];
    /** Links to pages of the site that answer with something else than the page: url → [status, linked from]. */
    links: { url: string; status: number; from: string }[];
    /** Every picture or video file the pages point at, and what it answers. */
    media: { url: string; status: number; contentType: string; from: string }[];
    /** Links out of the site, counted by host (never followed). */
    external: Record<string, number>;
    /** Links to the platform itself (an ad's click link): counted, never followed, since following one counts a click. */
    platform: number;
    /** Links to the site's own routes that act (/api/...): counted, never followed. */
    actions: string[];
};

const SECTIONS = ['news', 'podcast', 'success-stories', 'made-in-nablus', 'history', 'initiatives', 'round-table', 'awareness'];
const POLICIES = ['privacy', 'terms', 'cookies', 'copyright', 'editorial-policy', 'corrections-policy', 'sponsored-policy'];

/** The kind of a page by its address: one of each kind is what the accessibility check opens. */
export function kindOf(address: string): string {
    const url = new URL(address, SITE_URL);
    const path = url.pathname.replace(/\/$/, '') || '/';
    const filtered = url.search && !path.startsWith('/search') ? ', filtered' : '';
    const paged = (base: string) => (/\/page\/\d+$/.test(path) ? `${base}, page n` : base);
    const parts = path.split('/').filter(Boolean);
    const sections = SECTIONS.join('|');

    if (path === '/') return 'home';
    if (/\.(xml|txt)$/.test(path) || path.startsWith('/feeds/') || path.startsWith('/sitemaps/')) return 'file';
    if (new RegExp(`^/(${sections})/\\d+/[^/]+$`).test(path)) return `post: ${parts[0]}`;
    if (new RegExp(`^/(${sections})/\\d+$`).test(path)) return 'post without its slug';
    if (new RegExp(`^/(${sections})(/page/\\d+)?$`).test(path)) return paged(`section: ${parts[0]}`) + filtered;
    if (/^\/awareness\/characters\/[^/]+$/.test(path)) return 'awareness character';
    if (/^\/(category|tag|author|guest)\/[^/]+(\/page\/\d+)?$/.test(path)) return paged(parts[0]) + filtered;
    if (/^\/video\/\d+$/.test(path)) return 'video';
    if (/^\/video\/playlists\/[^/]+$/.test(path)) return 'playlist';
    if (/^\/video(\/page\/\d+)?$/.test(path)) return paged('video library') + filtered;
    if (/^\/currencies\/[^/]+$/.test(path)) return 'currency';
    if (/^\/weather\/[^/]+$/.test(path)) return 'city weather';
    if (/^\/services\/[^/]+$/.test(path)) return 'service';
    if (POLICIES.includes(parts[0]) && parts.length === 1) return 'policy page';
    return path.slice(1);
}

/** Pages that are noindex on purpose: a visitor's own search, and the newsletter's forms. A filtered list is too. */
export const NOINDEX_PAGES = ['/search', '/newsletter', '/newsletter/unsubscribe'];
