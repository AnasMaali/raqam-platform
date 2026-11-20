import { existsSync, mkdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

import { LOG_FILE, QA_URL } from './qa-env';
import { signIn } from './panel-session';
import { SITE_LOG } from './site-env';

export { STATE_FILE } from './panel-session';

/** Where the servers' logs stood when this run began: the teardown reads only what the run added. */
export const LOGS_FILE = new URL('../.qa/logs-at-start.json', import.meta.url).pathname;
const size = (file: string) => (existsSync(file) ? statSync(file).size : 0);

/** Checks the QA servers answer, notes where their logs stand, and signs the QA admin in for every check after it. */
export default async function globalSetup() {
    // Not /up: it also wants the scheduler and a worker, which a QA run does not need, and it alerts the admins.
    const up = await fetch(`${QA_URL}/api/v1/site`).catch(() => null);
    if (!up?.ok) throw new Error(`The QA server does not answer at ${QA_URL}: start it with backend/scripts/qa.sh serve.`);
    mkdirSync(dirname(LOGS_FILE), { recursive: true });
    writeFileSync(LOGS_FILE, JSON.stringify({ laravel: size(LOG_FILE), site: size(SITE_LOG) }));
    await signIn();
}
