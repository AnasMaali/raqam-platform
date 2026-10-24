import { createHmac } from 'node:crypto';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { test as base, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire } from '../support/panel';
import { livePost } from '../support/stats';

// Phase 9, decision 21.11: the admin orders the home page by dragging blocks, hides one, and the site is told at once.

type Hook = { url: string; calls: { body: string; time: string; signature: string }[] };
const SECRET = 'e2e-home-webhook-secret-0123456789';

const test = base.extend<{ hook: Hook }>({
    hook: async ({}, use) => {
        const calls: Hook['calls'] = [];
        const server: Server = createServer((req, res) => {
            let body = '';
            req.on('data', (c) => (body += c));
            req.on('end', () => {
                calls.push({ body, time: String(req.headers['x-raqam-timestamp']), signature: String(req.headers['x-raqam-signature']) });
                res.writeHead(204).end();
            });
        });
        await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
        try {
            await use({ url: `http://127.0.0.1:${(server.address() as AddressInfo).port}/hook`, calls });
        } finally {
            await new Promise((r) => server.close(r));
        }
    },
    appOptions: async ({ hook }, use) => use({ env: { SITE_WEBHOOK_URL: hook.url, SITE_WEBHOOK_SECRET: SECRET } }),
});

const order = (app: { sql(s: string): string }) =>
    app.sql(`SELECT string_agg(block_type || CASE WHEN is_active THEN '' ELSE '(hidden)' END, ',' ORDER BY sort_order) FROM home_blocks`);

test('the home page: the suggested order saved, a block dragged to the top, one hidden; the API follows and the site is told', async ({ page, app, hook, adminPassword }) => {
    test.setTimeout(180_000);
    livePost(app, 'خبر الصفحة الرئيسية');
    await signIn(page, app, adminPassword);
    await page.goto(panel('/home-layout'));
    await expect(page.locator('.fi-main').getByText('ترتيب مقترح لم يُحفظ بعد')).toBeVisible();

    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ الترتيب' }).click(), 'save');
    await expect(page.getByText('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.').first()).toBeVisible();
    expect(order(app).split(',').slice(0, 4)).toEqual(['hero', 'breaking', 'markets', 'latest']);

    // Drag «العاجل» above «المادة الرئيسية». A drag that stops short (a slow machine) is done again from where it landed,
    // as an editor would; what matters is that the order on screen is the order saved.
    const items = page.locator('.fi-fo-repeater-item');
    // Every block holds the list of all types in its (hidden) picker: find a block by its own heading only.
    const block = (title: string) => items.filter({ has: page.locator('.fi-fo-repeater-item-header-label', { hasText: new RegExp(`^\\s*${title}\\s*$`) }) });
    const first = () => items.nth(0).locator('.fi-fo-repeater-item-header-label').first().innerText();
    for (let attempt = 0; attempt < 3 && !(await first()).includes('العاجل'); attempt++) {
        const handle = block('العاجل').locator('[x-sortable-handle]').first();
        await handle.hover();
        const from = (await handle.boundingBox())!;
        const to = (await items.nth(0).boundingBox())!;
        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();
        // Past the top edge of the first block, in small steps, so the list has time to open a place there.
        await page.mouse.move(from.x + from.width / 2, from.y - 10, { steps: 5 });
        await page.mouse.move(from.x + from.width / 2, (from.y + to.y) / 2, { steps: 20 });
        await page.mouse.move(from.x + from.width / 2, to.y - 12, { steps: 20 });
        await page.mouse.move(from.x + from.width / 2, to.y - 8, { steps: 5 });
        await page.waitForTimeout(300);
        await page.mouse.up();
        await page.waitForTimeout(500);
    }
    expect(await first(), 'the dragged block is on top').toContain('العاجل');

    // And «آخر الأخبار» up three places with its arrow (the way on a phone).
    // One click a step, each only after the list has redrawn with the block one place higher (a busy machine is slow).
    const place = async () => (await items.locator('.fi-fo-repeater-item-header-label').allInnerTexts()).findIndex((t) => t.trim() === 'آخر الأخبار');
    for (let at = await place(); at > 0; at = await place()) {
        await afterLivewire(page, () => block('آخر الأخبار').getByRole('button', { name: 'تحريك لأعلى' }).click());
        await expect.poll(place).toBe(at - 1);
    }
    expect(await first()).toContain('آخر الأخبار');

    // Hide «العاجل»: open it and switch it off.
    const breaking = block('العاجل');
    await breaking.locator('.fi-fo-repeater-item-header').click();
    await breaking.getByRole('switch', { name: 'ظاهر' }).click();
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ الترتيب' }).click(), 'save');
    await expect(page.getByText('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.').first()).toBeVisible();

    expect(order(app).split(',').slice(0, 4)).toEqual(['latest', 'breaking(hidden)', 'hero', 'markets']);
    const home = await (await page.request.get(`${app.url}/api/v1/home`)).json();
    const types = home.data.map((b: { type: string }) => b.type);
    expect(types[0]).toBe('latest');
    expect(types).not.toContain('breaking');
    expect(home.data[0].posts[0].title).toBe('خبر الصفحة الرئيسية');

    // Two saves, two calls to the site, each signed with the shared secret over "{timestamp}.{body}".
    await expect.poll(() => hook.calls.length).toBe(2);
    for (const call of hook.calls) {
        expect(JSON.parse(call.body)).toMatchObject({ type: 'home', change: 'layout', paths: ['/'] });
        expect(call.signature).toBe('sha256=' + createHmac('sha256', SECRET).update(`${call.time}.${call.body}`).digest('hex'));
    }
    expect(app.sql(`SELECT count(*) FROM audit_log_changes WHERE field = 'home.layout'`)).toBe('2');
});

test('a block that is refused says why and nothing changes', async ({ page, app, adminPassword }) => {
    // Two main stories (written straight to the table, as an old import might): the screen refuses to save them so.
    app.sql(`INSERT INTO home_blocks (block_type, sort_order) VALUES ('hero', 1), ('hero', 2)`);
    await signIn(page, app, adminPassword);
    await page.goto(panel('/home-layout'));
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ الترتيب' }).click(), 'save');

    await expect(page.getByText('هذا البلوك موجود مرة أخرى في الصفحة.').first()).toBeVisible();
    expect(order(app)).toBe('hero,hero');
});
