import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, mkdtempSync, openSync, readdirSync, readFileSync, rmSync, symlinkSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { dropIndexes, sharedEngine } from './meili';

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
    /**
     * Search on Meilisearch: true = the shared engine of the run, or an engine of the test's own. Its indexes are
     * created with search:setup under this test's prefix and dropped when the test ends. Off = PostgreSQL only.
     */
    search?: boolean | { url: string; key: string };
};

export type App = {
    url: string;
    db: string;
    storage: string;
    /** The index prefix of this test (its Meilisearch indexes start with it). */
    searchPrefix: string;
    /** Runs an artisan command against this test's own database and storage. */
    artisan(args: string[]): string;
    /**
     * The same without blocking this process: needed when the command calls a stand-in server that runs in the test
     * itself (a blocking call would stop it from answering). Resolves with the output, rejects on a failing exit.
     */
    artisanAsync(args: string[]): Promise<string>;
    /** Runs one SQL statement on this test's database and returns the rows as text (psql -At). */
    sql(statement: string): string;
    /** The Redis keys this test's server wrote (its own prefix only). */
    redisKeys(): string[];
    /** Every row of this test's database as text, for looking for what must never be stored. */
    dump(): string;
    /** The text of the application log (every e-mail is written here: MAIL_MAILER=log). */
    log(): string;
    /** What the PHP server itself wrote to stderr (a fatal error that never reached the application log). */
    serverLog(): string;
    /** The newest six-digit sign-in code in the mail log (the mail is sent after the response, so this waits for it). */
    lastCode(after?: number): Promise<string>;
    /** How many sign-in code mails have been written so far. */
    codesSent(): number;
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
        LOG_FILE: 'e2e.log',
        LOG_LEVEL: 'debug',
        MEDIA_DISK: 'local',
        // Nothing here may reach the outside world or a developer's real settings from backend/.env.
        SITE_WEBHOOK_URL: '',
        SITE_WEBHOOK_SECRET: '',
        API_SITE_TOKEN: 'e2e-site-token',
        YOUTUBE_API_KEY: '',
        YOUTUBE_API_BASE: '',
        YOUTUBE_IMAGE_BASE: '',
        YOUTUBE_FETCH_THUMBNAILS: 'false',
        // Never the real bank or OpenWeather: a port nothing listens on, unless the fixture points them at its stand-in.
        CURRENCY_BOI_BASE: 'http://127.0.0.1:9',
        OPENWEATHER_API_BASE: 'http://127.0.0.1:9',
        OPENWEATHER_API_KEY: '',
        R2_ACCESS_KEY_ID: '',
        R2_SECRET_ACCESS_KEY: '',
        R2_ENDPOINT: '',
        R2_PUBLIC_BUCKET: '',
        R2_PRIVATE_BUCKET: '',
        R2_PUBLIC_URL: '',
        PHP_CLI_SERVER_WORKERS: '4',
        // The engine is off unless a test asks for it, so backend/.env's development key and indexes are never used.
        MEILISEARCH_HOST: '',
        MEILISEARCH_KEY: '',
        MEILISEARCH_PREFIX: `${prefix}_`,
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

/** The web root of one test: the real public files, plus /storage pointing at this test's own public disk (storage:link). */
function newDocroot(storage: string): string {
    const root = join(storage, 'public');
    mkdirSync(root);
    for (const entry of readdirSync(join(BACKEND, 'public'))) {
        if (entry !== 'storage') symlinkSync(join(BACKEND, 'public', entry), join(root, entry));
    }
    symlinkSync(join(storage, 'app/public'), join(root, 'storage'));
    return root;
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
    const result = spawnSync('php', ['-d', 'memory_limit=512M', ...args], { cwd: BACKEND, env, encoding: 'utf8', input: '', timeout: 120_000 });
    if (result.status !== 0) throw new Error(`php ${args.join(' ')} failed:\n${result.stdout}\n${result.stderr}`);
    return result.stdout;
}

function phpAsync(args: string[], env: NodeJS.ProcessEnv): Promise<string> {
    return new Promise((resolve, reject) => {
        const child = spawn('php', ['-d', 'memory_limit=512M', ...args], { cwd: BACKEND, env, stdio: ['ignore', 'pipe', 'pipe'] });
        let out = '';
        child.stdout.on('data', (chunk) => (out += chunk));
        child.stderr.on('data', (chunk) => (out += chunk));
        const timer = setTimeout(() => child.kill('SIGKILL'), 120_000);
        child.on('close', (code) => {
            clearTimeout(timer);
            code === 0 ? resolve(out) : reject(new Error(`php ${args.join(' ')} failed:\n${out}`));
        });
    });
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
    const engine = options.search === true ? sharedEngine() : options.search || null;
    const env = laravelEnv(db, prefix, storage, port, {
        ...(engine ? { MEILISEARCH_HOST: engine.url, MEILISEARCH_KEY: engine.key } : {}),
        ...options.env,
    });

    psql('postgres', `CREATE DATABASE ${db} TEMPLATE ${TEMPLATE_DB}`);
    const docroot = newDocroot(storage);

    const serverLogFile = join(storage, 'logs/php-server.log');
    const serverErr = openSync(serverLogFile, 'a');
    const server: ChildProcess = spawn(
        'php',
        ['-d', 'variables_order=EGPCS', '-d', 'memory_limit=512M', '-d', 'upload_max_filesize=40M', '-d', 'post_max_size=48M', '-S', `127.0.0.1:${port}`, resolve(BACKEND, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
        { cwd: docroot, env, stdio: ['ignore', 'ignore', serverErr], detached: true },
    );
    closeSync(serverErr);

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

    if (engine) php(['artisan', 'search:setup'], env);

    const logFile = join(storage, 'logs/e2e.log');
    const log = () => (existsSync(logFile) ? readFileSync(logFile, 'utf8') : '');

    return {
        url,
        db,
        storage,
        searchPrefix: `${prefix}_`,
        artisan: (args) => php(['artisan', ...args], env),
        artisanAsync: (args) => phpAsync(['artisan', ...args], env),
        sql: (statement) => psql(db, statement),
        log,
        serverLog: () => (existsSync(serverLogFile) ? readFileSync(serverLogFile, 'utf8') : ''),
        redisKeys() {
            const keys: string[] = [];
            for (const db of Object.values(REDIS)) {
                keys.push(...spawnSync('redis-cli', ['-n', db, '--scan', '--pattern', `${prefix}*`], { encoding: 'utf8' }).stdout.split('\n').filter(Boolean));
            }
            return keys;
        },
        dump() {
            const result = spawnSync('pg_dump', ['--data-only', '--inserts', '-d', db], { env: psqlEnv(), encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
            if (result.status !== 0) throw new Error(`pg_dump failed: ${result.stderr}`);
            return result.stdout;
        },
        codesSent: () => codeMatches(log()).length,
        async lastCode(after = 0) {
            for (let attempt = 0; attempt < 100; attempt++) {
                const codes = codeMatches(log());
                if (codes.length > after) return codes[codes.length - 1];
                await new Promise((r) => setTimeout(r, 100));
            }
            throw new Error('No sign-in code in the mail log.');
        },
        async stop() {
            try {
                if (server.pid) process.kill(-server.pid, 'SIGKILL');
            } catch {
                // already gone
            }
            forgetRedisKeys(prefix);
            if (engine) await dropIndexes(engine, `${prefix}_`);
            try {
                psql('postgres', `DROP DATABASE IF EXISTS ${db} WITH (FORCE)`);
            } catch {
                // a leftover database is removed by the next run's cleanup
            }
            rmSync(storage, { recursive: true, force: true });
        },
    };
}

// The mail is written to the log quoted-printable; the code sits alone in a paragraph.
function codeMatches(text: string): string[] {
    const clean = text.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
    return [...clean.matchAll(/letter-spacing:8px[^>]*>\s*(\d{6})\s*</g)].map((m) => m[1]);
}

/** Removes databases left behind by an interrupted run. */
export function dropLeftovers(): void {
    const rows = psql('postgres', "SELECT datname FROM pg_database WHERE datname LIKE 'raqam_e2e_%' AND datname <> '" + TEMPLATE_DB + "'");
    for (const name of rows.split('\n').filter(Boolean)) psql('postgres', `DROP DATABASE IF EXISTS ${name} WITH (FORCE)`);
}
