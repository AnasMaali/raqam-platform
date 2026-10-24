import type { Page } from '@playwright/test';
import type { App } from './app';
import { picture } from './ads';

/** Helpers for the phase 9 browser tests: published posts written straight to the database, and the site's reports. */

/** A person's phone browser as the site's server names it (a robot or "HeadlessChrome" is never counted). */
export const PHONE_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

/** A live news item in the seeded «محلي (نابلس)» category, published an hour ago. */
export function livePost(app: App, title: string): number {
    const category = app.sql(`SELECT id FROM categories WHERE name_ar = 'محلي (نابلس)'`);
    const cover = picture(app, `cover-${Math.random().toString(36).slice(2)}.jpg`, 1200, 800);
    return Number(app.sql(`INSERT INTO posts (kind_code, title, slug, status, published_at, category_id, body, cover_media_id)
        VALUES ('news', '${title}', 'p-${Math.random().toString(36).slice(2)}', 'published', now() - interval '1 hour', ${category}, '<p>نص</p>', ${cover}) RETURNING id`));
}

/** A report of the site's own server to /stats/hits: its token, the visitor's address and browser. */
export async function hit(page: Page, app: App, body: Record<string, unknown>, ip = '203.0.113.7', agent = PHONE_AGENT, token: string | null = 'e2e-site-token') {
    const headers: Record<string, string> = { 'X-Raqam-Visitor-Ip': ip, 'X-Raqam-Visitor-Ua': agent };
    if (token !== null) headers['X-Raqam-Site-Token'] = token;
    return page.request.post(`${app.url}/api/v1/stats/hits`, { headers, data: body });
}
