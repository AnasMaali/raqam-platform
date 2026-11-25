import { execFileSync } from 'node:child_process';
import { appendFileSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

import { expect, test } from '@playwright/test';

import { BACKEND, QA_URL, panel, psql, qaSetting, screenList } from './qa-env';

/*
 * The query budget (docs/qa/performance.md): every GET endpoint of the API and every screen of the panel, on the QA
 * database, may make at most the number of database queries written for it in query-budgets.json. Counted by the QA
 * server itself (X-Raqam-Queries, QUERY_REPORT in backend/scripts/qa.sh serve), each request served from an empty cache
 * of its own (X-Raqam-Cache: off): the number is what a first visit costs, whatever the shared cache holds.
 * A screen is counted whole: its page and the requests its parts make after it (lazy widgets, deferred tables).
 *
 * A budget is never raised to make this pass without a reason: a query more is a choice, written down with the change.
 * The counts of each run are in test-results/query-counts.jsonl, and `node qa/query-budgets.mjs` writes them as the new
 * budgets (after a deliberate change only).
 */

const BUDGETS_FILE = new URL('./query-budgets.json', import.meta.url).pathname;
const COUNTS_FILE = new URL('../test-results/query-counts.jsonl', import.meta.url).pathname;
const budgets = JSON.parse(readFileSync(BUDGETS_FILE, 'utf8')) as Record<string, number>;
const COUNT = 'x-raqam-queries';
const COLD = { 'X-Raqam-Cache': 'off' };

function note(key: string, count: number) {
    mkdirSync(dirname(COUNTS_FILE), { recursive: true });
    appendFileSync(COUNTS_FILE, JSON.stringify({ key, count }) + '\n');
}

const counted = new Map<string, number>();

/** A soft check: every endpoint and screen is counted and reported in one run, not only the first over its budget. */
function withinBudget(key: string, count: number) {
    note(key, count);
    counted.set(key, count);
    expect.soft(budgets[key], `${key} has no budget in query-budgets.json (measured: ${count})`).toBeDefined();
    if (budgets[key] !== undefined) expect.soft(count, `${key}: ${count} queries, budget ${budgets[key]}`).toBeLessThanOrEqual(budgets[key]);
}

/** GET routes of the API that are not counted, and why. */
const NOT_COUNTED: Record<string, string> = {
    'ads/click/{campaign}/{placement}/{signature}': 'a visit counts a click',
    'account/bookings': 'customer accounts are switched off in V1 (no public page)',
    'account/follows': 'customer accounts are switched off in V1 (no public page)',
    'account/profile': 'customer accounts are switched off in V1 (no public page)',
    'account/saved': 'customer accounts are switched off in V1 (no public page)',
};

const api = (path: string) => `${QA_URL}/api/v1/${path}`;
const first = (rows: string) => rows.split('\n').filter(Boolean)[0];

/**
 * The post counted for each kind: the newest live one (the API's own rule: published, due, not deleted). The QA data is
 * made with a fixed seed, so these are the same posts every time; `query-sample.json` records them with their parts
 * (docs/qa/performance.md), and a run on other data stops here instead of comparing counts of other posts.
 */
const postsByKind = () => {
    const rows = psql("SELECT DISTINCT ON (kind_code) kind_code || ' ' || id FROM posts WHERE status = 'published' AND published_at <= now() AND deleted_at IS NULL ORDER BY kind_code, id DESC")
        .split('\n')
        .filter(Boolean)
        .map((row) => row.split(' ') as [string, string]);
    const sample = JSON.parse(readFileSync(new URL('./query-sample.json', import.meta.url).pathname, 'utf8')) as { seed: number; posts: number; sample: Record<string, { id: number }> };
    const found = Object.fromEntries(rows.map(([kind, id]) => [kind, Number(id)]));
    const wanted = Object.fromEntries(Object.entries(sample.sample).map(([kind, part]) => [kind, part.id]));
    expect(found, `the QA data is not the documented sample: build it with raqam:demo --seed=${sample.seed} --posts=${sample.posts}`).toEqual(wanted);
    return rows;
};

/** The address of each route to count, with a real record of the QA database for each part in braces. */
async function addresses(get: (path: string) => Promise<unknown>): Promise<[string, string][]> {
    const list = async (path: string, key = 'slug') => {
        const body = (await get(path)) as { data: Record<string, unknown>[] };
        return String(body.data[0][key]);
    };
    const slug = {
        category: await list('categories'),
        tag: await list('tags'),
        section: await list('sections'),
        author: await list('authors'),
        guest: await list('guests'),
        page: await list('pages'),
        character: await list('characters'),
        playlist: await list('playlists'),
        city: await list('weather/cities'),
        video: await list('videos', 'id'),
    };
    const services = (await get('services')) as { data: { groups: { services: { slug: string }[] }[] } };
    const service = services.data.groups.flatMap((g) => g.services)[0]?.slug;
    const moved = first(psql('SELECT from_path FROM redirects ORDER BY id LIMIT 1'));
    const e = encodeURIComponent;

    const out: [string, string][] = [];
    const routes = execFileSync(join(BACKEND, 'scripts/qa.sh'), ['artisan', 'route:list', '--json', '--method=GET', '--path=api/v1'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    for (const { uri } of JSON.parse(routes.slice(routes.indexOf('['))) as { uri: string }[]) {
        const route = uri.replace(/^api\/v1\//, '');
        if (route in NOT_COUNTED) continue;
        const posts = route.startsWith('posts/{id}') ? postsByKind() : [];
        if (posts.length) {
            for (const [kind, id] of posts) {
                const path = (await get(`posts/${id}`)) as { data: { slug: string } };
                out.push([`${route} (${kind})`, route.replace('{id}', id).replace('{slug}', e(path.data.slug))]);
            }
            continue;
        }
        const fill: Record<string, string> = {
            'authors/{slug}': `authors/${e(slug.author)}`,
            'categories/{slug}': `categories/${e(slug.category)}`,
            'characters/{slug}': `characters/${e(slug.character)}`,
            'characters/{slug}/cards': `characters/${e(slug.character)}/cards`,
            'currencies/{code}/history': 'currencies/usd/history',
            'feeds/categories/{slug}': `feeds/categories/${e(slug.category)}`,
            'feeds/sections/{slug}': `feeds/sections/${e(slug.section)}`,
            'guests/{slug}': `guests/${e(slug.guest)}`,
            'pages/{slug}': `pages/${e(slug.page)}`,
            'playlists/{slug}': `playlists/${e(slug.playlist)}`,
            'redirects/lookup': `redirects/lookup?path=${e(moved)}`,
            'search': `search?q=${e('الذهب')}`,
            'sections/{slug}': `sections/${e(slug.section)}`,
            'services/{slug}': `services/${e(service)}`,
            'tags/{slug}': `tags/${e(slug.tag)}`,
            'videos/{id}': `videos/${slug.video}`,
            'weather/{slug}': `weather/${e(slug.city)}`,
        };
        if (route.includes('{') && !(route in fill)) throw new Error(`No QA record for the API route ${route}: add one here.`);
        out.push([route, fill[route] ?? route]);
    }
    return out;
}

/** No budget left for an endpoint or a screen that is gone; and the counts of the run, for the record. */
function noStaleBudgets(prefix: string) {
    const mine = [...counted].filter(([key]) => key.startsWith(prefix));
    expect.soft(Object.keys(budgets).filter((key) => key.startsWith(prefix) && !counted.has(key)), 'budgets of what no longer exists').toEqual([]);
    console.log(mine.map(([key, count]) => `${String(count).padStart(5)} / ${String(budgets[key] ?? '-').padStart(4)}  ${key}`).join('\n'));
}

test.describe('the query budget', () => {
    test('every GET endpoint of the API, served from an empty cache', async ({ playwright }) => {
        test.setTimeout(5 * 60_000);
        // As the website asks: with its token (the larger request limit), from its server.
        const request = await playwright.request.newContext({ extraHTTPHeaders: { 'X-Raqam-Site-Token': qaSetting('API_SITE_TOKEN'), ...COLD } });
        const get = async (path: string) => {
            const answer = await request.get(api(path));
            expect(answer.status(), path).toBe(200);
            return answer.json();
        };
        for (const [key, path] of await addresses(get)) {
            const answer = await request.get(api(path), { maxRedirects: 0 });
            expect.soft(answer.status(), `${key}: ${path}`).toBeLessThan(400);
            const count = Number(answer.headers()[COUNT]);
            expect(Number.isInteger(count), `${key}: no ${COUNT} header (is QUERY_REPORT on in the QA server?)`).toBe(true);
            withinBudget(`api ${key}`, count);
        }
        await request.dispose();
        noStaleBudgets('api ');
    });

    test('every screen of the panel, with what it loads after the page', async ({ page }) => {
        test.setTimeout(20 * 60_000);
        await page.setExtraHTTPHeaders(COLD);
        let total = 0;
        page.on('response', (answer) => {
            const count = answer.headers()[COUNT];
            if (count !== undefined && answer.url().startsWith(QA_URL)) total += Number(count);
        });
        for (const { path, key } of screenList()) {
            if (path.endsWith('(no record)')) continue;
            total = 0;
            const answer = await page.goto(panel(path === '/' ? '' : path), { waitUntil: 'networkidle' });
            expect.soft(answer?.status(), `panel ${path}`).toBe(200);
            withinBudget(`panel ${key}`, total);
        }
        noStaleBudgets('panel ');
    });
});
