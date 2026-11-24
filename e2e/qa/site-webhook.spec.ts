import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

import { expect, test, type Page } from '@playwright/test';

import { BACKEND, panel, psql } from './qa-env';
import { SITE_URL } from './site-env';

/*
 * The panel's changes reach the QA website through the platform's webhook (SendSiteWebhook → /api/revalidate), done as
 * an editor does them, in the panel: a post published (on the site, on the home page, in the sitemap), its address
 * changed (the old one moves with a 301), the post deleted (404); the home page's order, a menu, a setting. Each change
 * is put back afterwards, so the QA data stays as the other checks read it. Runs after them: it changes what they read.
 */

test.describe.configure({ mode: 'serial' });

const SITE = new URL(SITE_URL).origin;
const TITLE = `خبر فحص الربط ${Date.now().toString(36)}`;
let post = { id: 0, path: '' };

// A run stopped half way leaves no test post on the QA website: it goes to the trash as the panel sends it there.
test.afterAll(() => {
    if (post.id === 0 || psql(`SELECT deleted_at IS NULL FROM posts WHERE id = ${post.id}`) !== 't') return;
    execFileSync(join(BACKEND, 'scripts/qa.sh'), ['artisan', 'tinker', `--execute=app(App\\Content\\PostSaver::class)->trash(App\\Models\\Post::findOrFail(${post.id}));`], { stdio: 'pipe' });
});

/** The site's answer for a path, as a visitor's first request (no redirect followed). */
const site = (path: string) => fetch(`${SITE}${encodeURI(path)}`, { redirect: 'manual', headers: { 'user-agent': 'raqam-qa-webhook' } });
const siteText = async (path: string) => (await site(path)).text();
/** Until the site shows the change: the webhook goes through the queue, then the site refreshes what it keeps. */
const soon = { timeout: 60_000, intervals: [500, 1000, 2000] };

async function afterLivewire(page: Page, trigger: () => Promise<unknown>, method?: string) {
    const answered = page.waitForResponse(
        (r) => r.url().includes('/livewire') && r.request().method() === 'POST' && (method === undefined || (r.request().postData() ?? '').includes(`"method":"${method}"`)),
    );
    await trigger();
    await answered;
}

async function pick(page: Page, fieldId: string, option: string | RegExp, search?: string) {
    const button = page.locator(`[id="form.${fieldId}"]`).first();
    await button.click();
    const list = page.locator(`[id="${await button.getAttribute('aria-controls')}"]`);
    if (search !== undefined) await page.getByPlaceholder(/ابدأ بالكتابة للبحث/).locator('visible=true').last().fill(search);
    await list.getByRole('option', { name: option }).first().click();
}

async function sitemapHas(path: string): Promise<boolean> {
    const index = await siteText('/sitemap.xml');
    for (const [, loc] of index.matchAll(/<loc>([^<]+)<\/loc>/g)) {
        if (!loc.includes('/sitemaps/posts-')) continue;
        if ((await (await fetch(loc)).text()).includes(`${SITE}${encodeURI(path)}<`)) return true;
    }
    return false;
}

test('a post published in the panel is on the site, on its home page and in its sitemap', async ({ page }) => {
    test.setTimeout(180_000);
    const cover = psql("SELECT original_name FROM media_assets WHERE visibility = 'public' AND mime_type LIKE 'image/%' AND width >= 1200 ORDER BY id LIMIT 1");
    await page.goto(panel('/posts/create'));
    await page.locator('input[id="form.title"]').fill(TITLE);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL(/posts\/\d+\/edit/);
    post.id = Number(page.url().match(/posts\/(\d+)\/edit/)![1]);

    await page.locator('textarea[id="form.excerpt"]').fill('ملخص خبر الفحص.');
    const editor = page.locator('.tiptap.ProseMirror').first();
    await editor.click();
    await editor.pressSequentially('نص خبر الفحص، يُنشر من اللوحة ويُحذف بعدها.', { delay: 5 });
    await pick(page, 'category_id', 'محلي (نابلس)');
    await pick(page, 'cover_media_id', cover, cover.replace(/\.\w+$/, ''));
    await page.getByRole('button', { name: 'نشر', exact: true }).click();
    await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click());
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();

    post.path = `/news/${post.id}/${psql(`SELECT slug FROM posts WHERE id = ${post.id}`)}`;
    await expect.poll(async () => (await site(post.path)).status, { ...soon, message: 'the post answers on the site' }).toBe(200);
    expect(await siteText(post.path)).toContain(TITLE);
    await expect.poll(async () => (await siteText('/')).includes(TITLE), { ...soon, message: 'the home page shows it' }).toBe(true);
    await expect.poll(() => sitemapHas(post.path), { ...soon, message: 'the sitemap lists it' }).toBe(true);
});

test('its address changed in the panel: the old one moves to the new one with a 301', async ({ page }) => {
    test.setTimeout(120_000);
    expect(post.id, 'the post of the first test').toBeGreaterThan(0);
    const old = post.path;
    const slug = `عنوان-جديد-لخبر-الفحص-${Date.now().toString(36)}`;
    await page.goto(panel(`/posts/${post.id}/edit`));
    const field = page.locator('input[id="form.slug"]');
    await field.fill(slug);
    await afterLivewire(page, () => field.blur());
    await afterLivewire(page, () => page.getByRole('button', { name: 'تحديث' }).click(), 'save');
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();

    post.path = `/news/${post.id}/${slug}`;
    await expect.poll(async () => (await site(post.path)).status, { ...soon, message: 'the new address answers' }).toBe(200);
    // The new address answers as soon as it is saved (the site asks the platform about an address it has not seen);
    // the old one moves when the webhook arrives, through the queue. Then on every visit, and always with its address.
    await expect.poll(async () => (await site(old)).status, { ...soon, message: 'the old address moves' }).toBe(301);
    for (let i = 0; i < 3; i++) {
        const moved = await site(old);
        expect(moved.status, `visit ${i + 1} of the old address`).toBe(301);
        expect(decodeURI(new URL(moved.headers.get('location') ?? '', SITE).pathname)).toBe(post.path);
    }
});

test('the post deleted in the panel: its page is gone (404), and so is it from the home page and the sitemap', async ({ page }) => {
    test.setTimeout(120_000);
    expect(post.id, 'the post of the first test').toBeGreaterThan(0);
    await page.goto(panel(`/posts/${post.id}/edit`));
    await page.getByRole('button', { name: 'نقل إلى السلة' }).click();
    // The confirmation's red button says what it does.
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'نقل إلى السلة' }).click();
    await page.waitForURL(/\/posts\/?(\?.*)?$/);

    await expect.poll(async () => (await site(post.path)).status, { ...soon, message: 'its page' }).toBe(404);
    await expect.poll(async () => (await siteText('/')).includes(TITLE), { ...soon, message: 'the home page' }).toBe(false);
    await expect.poll(() => sitemapHas(post.path), { ...soon, message: 'the sitemap' }).toBe(false);
});

const MOST_READ = /<h2[^>]*>الأكثر قراءة<\/h2>/;

/** «الأكثر قراءة» shown or hidden on the home page, as the editor sets it (a hidden block's name ends with «(مخفي)»). */
async function showMostRead(page: Page, shown: boolean) {
    await page.goto(panel('/home-layout'));
    const block = page.locator('.fi-fo-repeater-item').filter({ has: page.locator('.fi-fo-repeater-item-header-label', { hasText: /^\s*الأكثر قراءة( \(مخفي\))?\s*$/ }) });
    await block.locator('.fi-fo-repeater-item-header').click();
    const toggle = block.getByRole('switch', { name: 'ظاهر' });
    if (((await toggle.getAttribute('aria-checked')) === 'true') === shown) return;
    await toggle.click();
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ الترتيب' }).click(), 'save');
    await expect(page.getByText('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.').first()).toBeVisible();
}

test('the home page\'s order changed in the panel: a block hidden leaves the site\'s home page, and comes back', async ({ page }) => {
    test.setTimeout(180_000);
    // Shown, as the QA data has it (a run stopped half way may have left it hidden).
    await showMostRead(page, true);
    await expect.poll(async () => MOST_READ.test(await siteText('/')), { ...soon, message: 'the block is on the home page' }).toBe(true);
    await showMostRead(page, false);
    try {
        await expect.poll(async () => MOST_READ.test(await siteText('/')), { ...soon, message: 'the block leaves the home page' }).toBe(false);
    } finally {
        await showMostRead(page, true);
    }
    await expect.poll(async () => MOST_READ.test(await siteText('/')), { ...soon, message: 'the block is back' }).toBe(true);
});

test('a menu changed in the panel: the site\'s header shows the new name, then the old one again', async ({ page }) => {
    test.setTimeout(180_000);
    // The first item of the header the site draws: a link to the home page is left out (the header has its own).
    const label = psql("SELECT i.label_ar FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE m.key = 'header' AND i.parent_id IS NULL AND i.is_active AND i.url IS DISTINCT FROM '/' ORDER BY i.sort_order, i.id LIMIT 1");
    const renamed = `${label} (فحص)`;
    const rename = async (from: string, to: string) => {
        await page.goto(panel('/menus'));
        // The header's tab is the first; its items are collapsed, each named by its label.
        const name = new RegExp(`^\\s*${from.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`);
        const item = page.locator('.fi-fo-repeater-item').filter({ has: page.locator('.fi-fo-repeater-item-header-label', { hasText: name }) }).first();
        await item.locator('.fi-fo-repeater-item-header').first().click();
        const field = item.locator('input[id$=".label_ar"]').first();
        await expect(field, `the header item «${from}» on the menus screen`).toHaveValue(from);
        await field.fill(to);
        await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ القوائم' }).click(), 'save');
        await expect(page.getByText('تم حفظ القوائم.').first()).toBeVisible();
    };
    const header = async () => /<header[\s\S]*?<\/header>/.exec(await siteText('/'))?.[0] ?? '';

    await rename(label, renamed);
    try {
        await expect.poll(async () => (await header()).includes(renamed), { ...soon, message: 'the header shows the new name' }).toBe(true);
    } finally {
        await rename(renamed, label);
    }
    await expect.poll(async () => (await header()).includes(renamed), { ...soon, message: 'the header shows the old name again' }).toBe(false);
});

test('a setting changed in the panel: the site\'s footer shows the new address, then the old one again', async ({ page }) => {
    test.setTimeout(180_000);
    const before = psql("SELECT value FROM settings WHERE key = 'contact.address'");
    const changed = 'شارع الفحص 7، نابلس';
    const set = async (value: string) => {
        await page.goto(panel('/settings'));
        await page.getByLabel('العنوان', { exact: true }).fill(value);
        await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ', exact: true }).click(), 'save');
        await expect(page.getByText('تم حفظ الإعدادات.').first()).toBeVisible();
    };
    const footer = async () => /<footer[\s\S]*?<\/footer>/.exec(await siteText('/about'))?.[0] ?? '';

    await set(changed);
    try {
        await expect.poll(async () => (await footer()).includes(changed), { ...soon, message: 'the footer shows the new address' }).toBe(true);
    } finally {
        await set(before);
    }
    await expect.poll(async () => (await footer()).includes(before), { ...soon, message: 'the footer shows the old address again' }).toBe(true);
});
