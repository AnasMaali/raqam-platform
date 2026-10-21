import { randomBytes } from 'node:crypto';
import { buildTemplate, dropLeftovers, newKey } from './app';
import { startMeili } from './meili';

export default async function globalSetup() {
    dropLeftovers();
    process.env.E2E_APP_KEY = newKey();
    // A fresh password every run; it exists only in the environment of this run.
    process.env.E2E_ADMIN_PASSWORD = 'Aa1-' + randomBytes(18).toString('base64url');
    buildTemplate(process.env.E2E_ADMIN_PASSWORD);

    // One Meilisearch for the run, for the tests that search (each on its own index prefix).
    const engine = await startMeili();
    process.env.E2E_MEILI_URL = engine.url;
    process.env.E2E_MEILI_KEY = engine.key;
    process.env.E2E_MEILI_PID = String(engine.pid);
    process.env.E2E_MEILI_DIR = engine.dir;
}
