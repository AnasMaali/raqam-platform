import { dropTemplate } from './app';
import { stopEngine } from './meili';

export default function globalTeardown() {
    dropTemplate();
    if (process.env.E2E_MEILI_PID && process.env.E2E_MEILI_DIR) stopEngine(Number(process.env.E2E_MEILI_PID), process.env.E2E_MEILI_DIR);
}
