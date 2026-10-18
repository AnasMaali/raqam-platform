import { createHash } from 'node:crypto';
import { test, expect, signIn, submitPassword } from '../support/fixtures';
import { ADMIN_EMAIL } from '../support/app';
import { publishedNews, uploadImage } from '../support/panel';

// Decisions 22.17 and 24.14: no raw IP address is stored, and no secret reaches a log or the database.
// Everything here runs from 127.0.0.1, so that string is what a leak would look like.

test('after real use there is no raw IP address and no secret in the database, the cache keys or the log', async ({ page, browser, app, adminPassword }) => {
    // A failed sign-in, a good one, an upload (Livewire's endpoint), a publication, the public API and a preview link.
    await submitPassword(page, ADMIN_EMAIL, 'Wrong-Password-123');
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await publishedNews(page, 'خبر الخصوصية');
    await page.request.get(`${app.url}/api/v1/posts`);
    await page.request.get(`${app.url}/api/v1/posts/${id}`);

    const everyone = await browser.newContext();
    for (let i = 0; i < 3; i++) await everyone.request.get(`${app.url}/api/v1/posts`);
    await everyone.close();

    // 1. The database: not a raw IP, not a password, not a code.
    const rows = app.dump();
    expect(rows, 'database').not.toMatch(/\b127\.0\.0\.1\b/);
    expect(rows, 'database').not.toContain(adminPassword);
    expect(rows).toContain('INSERT INTO public.login_attempts');   // the dump really contains what it should

    // 2. The counters in Redis are keyed by a hash, never by the address.
    const keys = app.redisKeys();
    expect(keys.length).toBeGreaterThan(0);
    expect(keys.filter((k) => k.includes('127.0.0.1')), 'redis keys').toEqual([]);
    const bareHash = createHash('sha1').update('127.0.0.1').digest('hex');
    expect(keys.filter((k) => k.includes(bareHash)), 'unsalted hash of the address').toEqual([]);

    // 3. The log: no address, no password, no key.
    const log = app.log();
    expect(log, 'log').not.toMatch(/\b127\.0\.0\.1\b/);
    expect(log, 'log').not.toContain(adminPassword);
    expect(log, 'log').not.toMatch(/e2e-only-(code|tracking)-key/);
});
