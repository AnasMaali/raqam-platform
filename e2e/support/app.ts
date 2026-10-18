import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

/**
 * One throw-away copy of the back end per test: its own PostgreSQL database (cloned from a template that
 * was migrated once), its own Redis key prefix, its own storage directory (uploads, log) and its own PHP
 * server on a free port. The developer's local database and Redis keys are never touched.
 */

export const BACKEND = resolve(import.meta.dirname, '../../backend');
export const TEMPLATE_DB = 'raqam_e2e_template';
export const ADMIN_EMAIL = 'e2e-admin@example.test';
export const ADMIN_PATH = 'e2e-panel';

// Redis databases kept for these tests (the ones the application and its own tests use are 3 to 8).
const REDIS = { default: '9', cache: '10', session: '11' };

// Compiled Blade views are shared by all tests of a run: compiling the panel's views is the slow part of a first request.
const SHARED_VIEWS = join(tmpdir(), 'raqam-e2e-views');

export type AppOptions = {
    /** Extra environment for the application, e.g. a short idle limit. */
    env?: Record<string, string>;
};

export type App = {
    url: string;
    db: string;
    storage: string;
    /** Runs an artisan command against this test's own database and storage. */
    artisan(args: string[]): string;
    /** Runs one SQL statement on this test's database and returns the rows as text (psql -At). */
    sql(statement: string): string;
    /** The text of the application log (every e-mail is written here: MAIL_MAILER=log). */
    log(): string;
    /** The newest six-digit sign-in code in the mail log. */
    lastCode(): string;
    stop(): Promise<void>;
};

const psqlEnv = () => ({ ...process.env, PGHOST: process.env.PGHOST ?? '127.0.0.1', PGUSER: process.env.PGUSER ?? 'raqam' });

export function psql(database: string, statement: string): string {
    const result = spawnSync('psql', ['-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', database, '-c', statement], { env: psqlEnv(), encoding: 'utf8' });
    if (result.status !== 0) throw new Error(`psql failed (${database}): ${result.stderr}`);
    return result.stdout.trim();
}

function laravelEnv(db: string, prefix: string, storage: string, port: number, extra: Record<string, string> = {}): NodeJS.ProcessEnv {
    const env: NodeJS.ProcessEnv = {
        ...process.env,
        APP_NAME: 'Raqam',
        APP_ENV: 'local',
        APP_DEBUG: 'false',
        APP_KEY: process.env.E2E_APP_KEY,
        APP_URL: `http://127.0.0.1:${port}`,
        LARAVEL_STORAGE_PATH: storage,
        VIEW_COMPILED_PATH: SHARED_VIEWS,
        DB_CONNECTION: 'pgsql',
        DB_HOST: process.env.PGHOST ?? '127.0.0.1',
        DB_PORT: process.env.PGPORT ?? '5432',
        DB_DATABASE: db,
        DB_USERNAME: process.env.PGUSER ?? 'raqam',
        ADMIN_PATH,
        ADMIN_CODE_KEY: 'e2e-only-code-key-0123456789abcdef',
        TRACKING_HASH_KEY: 'e2e-only-tracking-key-0123456789abcdef',
        ARGON_MEMORY: '1024',
        ARGON_TIME: '1',
        REDIS_DB: REDIS.default,
        REDIS_CACHE_DB: REDIS.cache,
        REDIS_SESSION_DB: REDIS.session,
        REDIS_PREFIX: `${prefix}_`,
        CACHE_PREFIX: `${prefix}_c_`,
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'log',
        LOG_CHANNEL: 'single',
        LOG_LEVEL: 'debug',
        MEDIA_DISK: 'local',
        // Nothing here may reach the outside world or a developer's real settings from backend/.env.
        SITE_WEBHOOK_URL: '',
        SITE_WEBHOOK_SECRET: '',
        API_SITE_TOKEN: 'e2e-site-token',
        R2_ACCESS_KEY_ID: '',
        R2_SECRET_ACCESS_KEY: '',
        R2_ENDPOINT: '',
        R2_PUBLIC_BUCKET: '',
        R2_PRIVATE_BUCKET: '',
        R2_PUBLIC_URL: '',
        PHP_CLI_SERVER_WORKERS: '4',
        ...extra,
    };
    if (process.env.PGPASSWORD) env.DB_PASSWORD = process.env.PGPASSWORD;
    if (process.env.DB_PASSWORD) env.DB_PASSWORD = process.env.DB_PASSWORD;
    return env;
}

function newStorage(): string {
    const storage = mkdtempSync(join(tmpdir(), 'raqam-e2e-storage-'));
    for (const dir of ['app/public', 'app/private', 'framework/cache', 'framework/sessions', 'framework/testing', 'framework/views', 'logs']) {
        mkdirSync(join(storage, dir), { recursive: true });
    }
    return storage;
}

function freePort(): Promise<number> {
    return new Promise((resolvePort, reject) => {
        const probe = createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const { port } = probe.address() as { port: number };
            probe.close(() => resolvePort(port));
        });
    });
}

function php(args: string[], env: NodeJS.ProcessEnv): string {
    const result = spawnSync('php', ['-d', 'memory_limit=512M', ...args], { cwd: BACKEND, env, encoding: 'utf8' });
    if (result.status !== 0) throw new Error(`php ${args.join(' ')} failed:\n${result.stdout}\n${result.stderr}`);
    return result.stdout;
}

/** Once per run: a migrated database with one admin, which every test clones. */
export function buildTemplate(adminPassword: string): void {
    mkdirSync(SHARED_VIEWS, { recursive: true });
    psql('postgres', `DROP DATABASE IF EXISTS ${TEMPLATE_DB}`);
    psql('postgres', `CREATE DATABASE ${TEMPLATE_DB}`);
    const storage = newStorage();
    const env = laravelEnv(TEMPLATE_DB, 'e2e_template', storage, 0);
    try {
        // The panel's own scripts and styles are published files (git-ignored), not routes.
        php(['artisan', 'filament:assets'], env);
        php(['artisan', 'migrate', '--force', '--no-interaction'], env);
        php([resolve(import.meta.dirname, 'seed-admin.php'), ADMIN_EMAIL, adminPassword], env);
    } finally {
        rmSync(storage, { recursive: true, force: true });
    }
}

export function dropTemplate(): void {
    psql('postgres', `DROP DATABASE IF EXISTS ${TEMPLATE_DB}`);
}

export function newKey(): string {
    return 'base64:' + randomBytes(32).toString('base64');
}

/** Removes only the keys this test wrote (never FLUSHDB: the instance is shared with other projects). */
function forgetRedisKeys(prefix: string): void {
    for (const db of Object.values(REDIS)) {
        const scan = spawnSync('redis-cli', ['-n', db, '--scan', '--pattern', `${prefix}*`], { encoding: 'utf8' });
        const keys = scan.stdout.split('\n').filter(Boolean);
        for (let i = 0; i < keys.length; i += 200) {
            spawnSync('redis-cli', ['-n', db, 'unlink', ...keys.slice(i, i + 200)], { encoding: 'utf8' });
        }
    }
}

export async function startApp(options: AppOptions = {}): Promise<App> {
    const id = randomBytes(5).toString('hex');
    const db = `raqam_e2e_${id}`;
    const prefix = `e2e_${id}`;
    const storage = newStorage();
    const port = await freePort();
    const env = laravelEnv(db, prefix, storage, port, options.env);

    psql('postgres', `CREATE DATABASE ${db} TEMPLATE ${TEMPLATE_DB}`);

    const server: ChildProcess = spawn(
        'php',
        ['-d', 'variables_order=EGPCS', '-d', 'memory_limit=512M', '-d', 'upload_max_filesize=40M', '-d', 'post_max_size=48M', '-S', `127.0.0.1:${port}`, resolve(BACKEND, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
        { cwd: resolve(BACKEND, 'public'), env, stdio: 'ignore', detached: true },
    );

    const url = `http://127.0.0.1:${port}`;
    for (let attempt = 0; ; attempt++) {
        try {
            if ((await fetch(`${url}/up`)).ok) break;
        } catch {
            // not listening yet
        }
        if (attempt > 150) throw new Error('The test server did not start.');
        await new Promise((r) => setTimeout(r, 100));
    }

    const logFile = join(storage, 'logs/laravel.log');
    const log = () => (existsSync(logFile) ? readFileSync(logFile, 'utf8') : '');

    return {
        url,
        db,
        storage,
        artisan: (args) => php(['artisan', ...args], env),
        sql: (statement) => psql(db, statement),
        log,
        lastCode() {
            // The mail is written to the log quoted-printable; the code sits alone in a paragraph.
            const text = log().replace(/=\r?\n/g, '').replace(/=3D/g, '=');
            const codes = [...text.matchAll(/letter-spacing:8px[^>]*>\s*(\d{6})\s*</g)];
            if (codes.length === 0) throw new Error('No sign-in code in the mail log.');
            return codes[codes.length - 1][1];
        },
        async stop() {
            try {
                if (server.pid) process.kill(-server.pid, 'SIGKILL');
            } catch {
                // already gone
            }
            forgetRedisKeys(prefix);
            try {
                psql('postgres', `DROP DATABASE IF EXISTS ${db} WITH (FORCE)`);
            } catch {
                // a leftover database is removed by the next run's cleanup
            }
            rmSync(storage, { recursive: true, force: true });
        },
    };
}

/** Removes databases left behind by an interrupted run. */
export function dropLeftovers(): void {
    const rows = psql('postgres', "SELECT datname FROM pg_database WHERE datname LIKE 'raqam_e2e_%' AND datname <> '" + TEMPLATE_DB + "'");
    for (const name of rows.split('\n').filter(Boolean)) psql('postgres', `DROP DATABASE IF EXISTS ${name} WITH (FORCE)`);
}
