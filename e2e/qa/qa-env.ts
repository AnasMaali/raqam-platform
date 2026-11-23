import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';

/*
 * The QA environment as the browser checks see it (docs/qa/README.md): the settings in backend/.env.qa, the QA admin in
 * ~/.raqam-qa-login, and the database raqam_qa. Nothing here is written down: the panel's address is read from
 * ADMIN_PATH each run, and never printed (a screen is named by its address after the panel's).
 */

export const BACKEND = resolve(import.meta.dirname, '../../backend');

function settings(file: string): Record<string, string> {
    const values: Record<string, string> = {};
    for (const line of readFileSync(file, 'utf8').split('\n')) {
        const m = /^([A-Za-z0-9_]+)=(.*)$/.exec(line.trim());
        if (m) values[m[1]] = m[2];
    }
    return values;
}

const env = settings(join(BACKEND, '.env.qa'));
if (env.DB_DATABASE !== 'raqam_qa') throw new Error('backend/.env.qa does not name raqam_qa; the QA checks run against the QA environment only.');
if (!env.ADMIN_PATH) throw new Error('backend/.env.qa has no ADMIN_PATH.');

export const QA_URL = env.APP_URL;

/** A value of backend/.env.qa. */
export function qaSetting(key: string): string {
    const value = env[key];
    if (!value) throw new Error(`backend/.env.qa has no ${key}.`);
    return value;
}
const ADMIN_PATH = env.ADMIN_PATH;
export const LOG_FILE = join(env.LARAVEL_STORAGE_PATH, 'logs/laravel.log');

/** A panel address from its path after the panel's own ("/posts/12/edit"). */
export const panel = (path = '') => `/${ADMIN_PATH}${path}`;

/** The same address with the panel's part taken away again, to name a screen in a test's title or a finding. */
export const screenName = (url: string) => new URL(url, QA_URL).pathname.replace(`/${ADMIN_PATH}`, '') || '/';

export function login(): { email: string; password: string } {
    const values = settings(join(homedir(), '.raqam-qa-login'));
    if (!values.email || !values.password) throw new Error('~/.raqam-qa-login has no email or password (backend/scripts/qa.sh admin makes it).');
    return { email: values.email, password: values.password };
}

export function psql(statement: string): string {
    const result = spawnSync('psql', ['-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', 'raqam_qa', '-c', statement], {
        env: { ...process.env, PGHOST: process.env.PGHOST ?? '127.0.0.1', PGUSER: process.env.PGUSER ?? 'raqam' },
        encoding: 'utf8',
    });
    if (result.status !== 0) throw new Error(`psql failed: ${result.stderr}`);
    return result.stdout.trim();
}

/** The panel's GET routes, as paths after the panel's own ("/posts/{record}/edit"). */
export function panelRoutes(): string[] {
    const json = execFileSync(join(BACKEND, 'scripts/qa.sh'), ['artisan', 'route:list', '--json', '--method=GET'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    const routes = JSON.parse(json.slice(json.indexOf('['))) as { uri: string }[];
    return routes
        .map((r) => r.uri)
        .filter((uri) => uri === ADMIN_PATH || uri.startsWith(`${ADMIN_PATH}/`))
        .map((uri) => uri.slice(ADMIN_PATH.length) || '/');
}

/** Routes that are not screens: the sign-in steps and the session's JSON answer. */
const NOT_SCREENS = new Set(['/login', '/login/code', '/session/status']);

/** The table of a resource whose name is not its address. */
const TABLES: Record<string, string> = { 'audit-logs': 'audit_log', synonyms: 'synonym_groups' };

/** The first row of a table by its primary key (a template's is its `key`), as the panel's address names it. */
function firstKey(table: string): string {
    const column = psql(`SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey) WHERE i.indrelid = '${table}'::regclass AND i.indisprimary`);
    return psql(`SELECT ${column} FROM ${table} ORDER BY ${column} LIMIT 1`);
}

/**
 * A screen to open: its address, with a record of its own for `{record}` (one post of each kind), and a key that names it
 * without a record id (the route, and the kind for a post), so the query budgets survive a rebuilt QA database.
 */
export type Screen = { path: string; key: string };

export function screenList(): Screen[] {
    const out: Screen[] = [];
    for (const route of panelRoutes()) {
        if (NOT_SCREENS.has(route)) continue;
        if (!route.includes('{')) {
            out.push({ path: route, key: route });
            continue;
        }
        const resource = route.split('/')[1];
        const rows =
            resource === 'posts'
                ? psql("SELECT DISTINCT ON (kind_code) id || ' ' || kind_code FROM posts WHERE deleted_at IS NULL ORDER BY kind_code, id")
                      .split('\n')
                      .filter(Boolean)
                      .map((row) => row.split(' ') as [string, string])
                      .map(([id, kind]): [string, string] => [id, `${route} (${kind})`])
                : [firstKey(TABLES[resource] ?? resource.replaceAll('-', '_'))].filter(Boolean).map((id): [string, string] => [id, route]);
        for (const [id, key] of rows) out.push({ path: route.replace('{record}', id), key });
        if (rows.length === 0) out.push({ path: `${route} (no record)`, key: `${route} (no record)` });
    }
    return out;
}

/** The addresses of the screens, for the crawl. */
export function screens(): string[] {
    return screenList().map((screen) => screen.path);
}
