import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { dirname } from 'node:path';

import { chromium } from '@playwright/test';

import { LOG_FILE, QA_URL, login, panel } from './qa-env';

/** Where the signed-in browser state is kept between runs (git-ignored: it holds a session). */
export const STATE_FILE = new URL('../.qa/panel-state.json', import.meta.url).pathname;

const codes = (text: string) => [...text.replace(/=\r?\n/g, '').replace(/=3D/g, '=').matchAll(/letter-spacing:8px[^>]*>\s*(\d{6})\s*</g)].map((m) => m[1]);

/** Signs the QA admin in once, as an editor does (password, then the code from the mail log), for every check after it. */
export default async function globalSetup() {
    const up = await fetch(`${QA_URL}/up`).catch(() => null);
    if (!up?.ok) throw new Error(`The QA server does not answer at ${QA_URL}: start it with backend/scripts/qa.sh serve.`);

    const browser = await chromium.launch();
    // The session from the last run, while it lasts (30 minutes idle): a new code is sent at most once a minute. Its
    // cookie is kept again as the server just renewed it: the saved copy's own expiry (30 minutes from when it was
    // saved) would end the session in the middle of a run.
    if (existsSync(STATE_FILE)) {
        const kept = await browser.newPage({ locale: 'ar', storageState: STATE_FILE });
        await kept.goto(`${QA_URL}${panel()}`);
        const signedIn = new URL(kept.url()).pathname.replace(/\/$/, '') === panel();
        if (signedIn) await kept.context().storageState({ path: STATE_FILE });
        await kept.close();
        if (signedIn) {
            await browser.close();
            return;
        }
    }

    const { email, password } = login();
    const page = await browser.newPage({ locale: 'ar' });
    const before = codes(readFileSync(LOG_FILE, 'utf8')).length;
    const logSize = statSync(LOG_FILE).size;
    await page.goto(`${QA_URL}${panel('/login')}`);
    await page.getByLabel('البريد الإلكتروني').fill(email);
    await page.locator('input[type=password]').first().fill(password);
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    await page.waitForURL(/login\/code/);

    let code: string | undefined;
    for (let i = 0; i < 100 && !code; i++) {
        const text = readFileSync(LOG_FILE, 'utf8');
        const found = codes(text);
        if (statSync(LOG_FILE).size > logSize && found.length > before) code = found.at(-1);
        else await new Promise((r) => setTimeout(r, 100));
    }
    if (!code) throw new Error('No sign-in code reached the QA mail log (a code is sent at most once a minute and three times an hour: try again later).');
    await page.getByLabel('رمز التحقق').fill(code);
    await page.getByRole('button', { name: 'تأكيد' }).click();
    await page.waitForURL((url) => url.pathname.replace(/\/$/, '') === panel());

    mkdirSync(dirname(STATE_FILE), { recursive: true });
    await page.context().storageState({ path: STATE_FILE });
    await browser.close();
}
