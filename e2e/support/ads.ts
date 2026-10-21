import type { Page } from '@playwright/test';
import type { App } from './app';

/** Helpers for the ads and services browser tests (phase 7). */

/** A person's browser: "HeadlessChrome" counts as a robot and is never counted. */
export const PERSON_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

export const SITE = { 'X-Raqam-Site-Token': 'e2e-site-token' };

export const api = async (page: Page, app: App, path: string) => (await page.request.get(`${app.url}/api/v1${path}`, { headers: SITE })).json();

/** A picture row of the library with these pixels (the screens and the API need no file for it). */
export function picture(app: App, name: string, w: number, h: number): number {
    return Number(app.sql(`INSERT INTO media_assets (path, original_name, mime_type, size_bytes, checksum_sha256, alt_text, width, height)
        VALUES ('e2e/${name}', '${name}', 'image/jpeg', 1000, encode(sha256('${name}'::bytea), 'hex'), 'إعلان', ${w}, ${h}) RETURNING id`));
}

export function advertiser(app: App, name: string): number {
    const org = app.sql(`INSERT INTO organizations (name, slug) VALUES ('${name}', 'org-${Math.random().toString(36).slice(2)}') RETURNING id`);
    return Number(app.sql(`INSERT INTO advertisers (organization_id) VALUES (${org}) RETURNING id`));
}

/** A campaign written straight to the database, live from `from` to `to` (SQL intervals), in the header slot. */
export function campaign(app: App, name: string, priority: number, from = "now() - interval '1 hour'", to = "now() + interval '1 day'"): number {
    const id = Number(app.sql(`INSERT INTO ad_campaigns (advertiser_id, name, target_url, starts_at, ends_at, priority, status)
        VALUES (${advertiser(app, `معلن ${name}`)}, '${name}', 'https://bank.example.ps/offer', ${from}, ${to}, ${priority}, 'active') RETURNING id`));
    app.sql(`INSERT INTO ad_campaign_placements VALUES (${id}, 'header_leaderboard')`);
    app.sql(`INSERT INTO ad_creatives VALUES (${id}, 'desktop', ${picture(app, `${name}-d-${id}.jpg`, 970, 90)}), (${id}, 'mobile', ${picture(app, `${name}-m-${id}.jpg`, 320, 100)})`);
    return id;
}

/** Rows written straight to the database bypass the services that drop the cached answers: drop them as those do. */
export function dropCaches(app: App) {
    app.artisan(['tinker', '--execute=Cache::increment("ads:version"); Cache::increment("services:version");']);
}

/** Palestine date and time parts some milliseconds from now. */
export async function hebronParts(page: Page, inMs: number): Promise<Record<string, string>> {
    return page.evaluate((ms) => {
        const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Hebron', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(new Date(Date.now() + ms));
        return Object.fromEntries(parts.map((x) => [x.type, x.value]));
    }, inMs);
}

/** Sets a Filament date-time picker (not native) of a page form, e.g. "form.starts_at". */
export async function setDateTime(page: Page, fieldId: string, t: Record<string, string>) {
    await page.locator(`[id="${fieldId}"]`).first().click();
    const picker = page.locator(`[id="${fieldId}-panel"]`);
    await picker.locator(`[data-date="${t.year}-${t.month}-${t.day}"]`).click();
    await picker.getByLabel('الساعة').fill(String(Number(t.hour)));
    await picker.getByLabel('الدقيقة').fill(String(Number(t.minute)));
    await picker.getByLabel('الدقيقة').blur();
    await page.waitForTimeout(500);
    await page.keyboard.press('Escape');
}
