import { spawn, type ChildProcess } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:net';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * A Meilisearch v1.54.3 of the browser tests' own: started on a free port with a master key made up for it,
 * data in a temporary directory, and a key scoped to the e2e index prefixes (the application never holds the
 * master key). One serves the whole run; a test that stops the engine starts one of its own.
 */

export const PREFIX_PATTERN = 'e2e_*';

export type Meili = { url: string; key: string; pid: number; dir: string; stop(): void };

const binary = () => {
    const bin = process.env.MEILISEARCH_BIN ?? join(homedir(), 'tools/meilisearch-1.54.3/meilisearch');
    if (!existsSync(bin)) throw new Error(`Meilisearch binary not found at ${bin}: set MEILISEARCH_BIN.`);
    return bin;
};

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

export async function startMeili(): Promise<Meili> {
    const port = await freePort();
    const dir = mkdtempSync(join(tmpdir(), 'raqam-e2e-meili-'));
    const master = randomBytes(24).toString('hex');
    const engine: ChildProcess = spawn(binary(), ['--http-addr', `127.0.0.1:${port}`, '--db-path', join(dir, 'data.ms'), '--no-analytics'], {
        cwd: dir, env: { ...process.env, MEILI_MASTER_KEY: master }, stdio: 'ignore', detached: true,
    });
    const url = `http://127.0.0.1:${port}`;
    const stop = () => {
        try {
            if (engine.pid) process.kill(-engine.pid, 'SIGKILL');
        } catch {
            // already gone
        }
        rmSync(dir, { recursive: true, force: true });
    };

    for (let attempt = 0; ; attempt++) {
        try {
            if ((await fetch(`${url}/health`)).ok) break;
        } catch {
            // not listening yet
        }
        if (attempt > 200) {
            stop();
            throw new Error('Meilisearch did not start.');
        }
        await new Promise((r) => setTimeout(r, 50));
    }

    // The same actions search:create-key grants: indexing and searching the e2e indexes, nothing global.
    const response = await fetch(`${url}/keys`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${master}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
            description: 'e2e', expiresAt: null, indexes: [PREFIX_PATTERN],
            actions: ['search', 'documents.add', 'documents.get', 'documents.delete', 'indexes.create', 'indexes.get', 'indexes.update', 'indexes.delete', 'settings.get', 'settings.update', 'tasks.get'],
        }),
    });
    if (!response.ok) {
        stop();
        throw new Error(`Could not create the e2e key: ${response.status}`);
    }

    return { url, key: (await response.json()).key, pid: engine.pid!, dir, stop };
}

/** The shared engine of the run (global setup), as environment for an application. */
export function sharedEngine(): { url: string; key: string } {
    const url = process.env.E2E_MEILI_URL;
    const key = process.env.E2E_MEILI_KEY;
    if (!url || !key) throw new Error('The shared Meilisearch of the run is not up (global setup).');
    return { url, key };
}

/** Waits until the engine has nothing queued for this application's indexes. */
export async function settle(engine: { url: string; key: string }, prefix: string): Promise<void> {
    for (let attempt = 0; attempt < 200; attempt++) {
        const r = await fetch(`${engine.url}/tasks?statuses=enqueued,processing&limit=100`, { headers: { Authorization: `Bearer ${engine.key}` } });
        const tasks = (await r.json()).results as { indexUid: string | null }[];
        if (!tasks.some((t) => t.indexUid?.startsWith(prefix))) return;
        await new Promise((r) => setTimeout(r, 50));
    }
    throw new Error('Meilisearch is still busy.');
}

/** Removes every index of an application (its prefix only). */
export async function dropIndexes(engine: { url: string; key: string }, prefix: string): Promise<void> {
    try {
        const r = await fetch(`${engine.url}/indexes?limit=100`, { headers: { Authorization: `Bearer ${engine.key}` } });
        for (const { uid } of (await r.json()).results as { uid: string }[]) {
            if (uid.startsWith(prefix)) await fetch(`${engine.url}/indexes/${uid}`, { method: 'DELETE', headers: { Authorization: `Bearer ${engine.key}` } });
        }
    } catch {
        // the engine may be the one a test stopped
    }
}

/** Stops an engine started by another process of the run (the global setup's). */
export function stopEngine(pid: number, dir: string): void {
    try {
        process.kill(-pid, 'SIGKILL');
    } catch {
        // already gone
    }
    rmSync(dir, { recursive: true, force: true });
}
