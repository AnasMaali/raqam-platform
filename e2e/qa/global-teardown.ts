import { existsSync, openSync, readFileSync, readSync, statSync } from 'node:fs';

import { LOGS_FILE } from './global-setup';
import { LOG_FILE } from './qa-env';
import { SITE_LOG } from './site-env';

/** What a file gained since `from`. */
function added(file: string, from: number): string {
    if (!existsSync(file)) return '';
    const length = statSync(file).size - from;
    if (length <= 0) return '';
    const buffer = Buffer.alloc(length);
    readSync(openSync(file, 'r'), buffer, 0, length, from);
    return buffer.toString('utf8');
}

/**
 * After every QA run: what the servers wrote while it ran. The website writes a [csp] line for each violation of its
 * content security policy a browser reports, and its errors (a page that failed to render) marked ⨯; the API writes
 * its errors as ERROR lines. Any of them fails the run, whichever check was running, with the lines themselves.
 */
export default async function globalTeardown() {
    if (!existsSync(LOGS_FILE)) return;
    const start = JSON.parse(readFileSync(LOGS_FILE, 'utf8')) as { laravel: number; site: number };
    const site = added(SITE_LOG, start.site).split('\n');
    const found = [
        ...site.filter((l) => l.includes('[csp]')).map((l) => `site: ${l.trim()}`),
        ...site.filter((l) => /⨯|\bError:/.test(l)).map((l) => `site: ${l.trim()}`),
        ...added(LOG_FILE, start.laravel)
            .split('\n')
            .filter((l) => /^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/.test(l))
            .map((l) => `API: ${l.slice(0, 300)}`),
    ];
    if (found.length > 0) {
        throw new Error(`The servers reported problems while the run went on (${found.length}):\n${[...new Set(found)].slice(0, 40).join('\n')}`);
    }
}
