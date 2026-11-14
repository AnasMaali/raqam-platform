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
