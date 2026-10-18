import { randomBytes } from 'node:crypto';
import { buildTemplate, dropLeftovers, newKey } from './app';

export default function globalSetup() {
    dropLeftovers();
    process.env.E2E_APP_KEY = newKey();
    // A fresh password every run; it exists only in the environment of this run.
    process.env.E2E_ADMIN_PASSWORD = 'Aa1-' + randomBytes(18).toString('base64url');
    buildTemplate(process.env.E2E_ADMIN_PASSWORD);
}
