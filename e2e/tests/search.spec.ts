import type { APIRequestContext } from '@playwright/test';
import { test, expect, panel, signIn } from '../support/fixtures';
import { startMeili, type Meili } from '../support/meili';
import { afterLivewire, createPost, fillRequired, pick, publishedNews, save, uploadImage } from '../support/panel';

// Search (decision 10), through the editor and the panel as an editor uses them, and through the API the site calls.
// The site's search page belongs to the frontend phase; here the browser makes the same request the site's server will.

type Hit = { id: number; title: string; highlight: { title: string } };
type Answer = { data: { engine: string; posts: Hit[]; tags: { name: string }[] } };

async function search(request: APIRequestContext, url: string, q: string, extra: Record<string, string> = {}): Promise<Answer> {
    const response = await request.get(`${url}/api/v1/search?${new URLSearchParams({ q, ...extra })}`);
    expect(response.status(), `search for ${q}`).toBe(200);
    expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
    return response.json();
}

/** Asks again until the item is (or is no longer) among the results, and says how long that took. */
async function until(request: APIRequestContext, url: string, q: string, id: number, present: boolean, seconds = 30): Promise<number> {
    const start = Date.now();
    for (;;) {
        const found = (await search(request, url, q)).data.posts.some((p) => p.id === id);
        if (found === present) return (Date.now() - start) / 1000;
        if (Date.now() - start > seconds * 1000) throw new Error(`«${q}»: item ${id} still ${present ? 'missing' : 'found'} after ${seconds}s`);
        await new Promise((r) => setTimeout(r, 250));
    }
}

const ids = (answer: Answer) => answer.data.posts.map((p) => p.id);

test.describe('search on the engine', () => {
    test.use({ appOptions: { search: true } });

    test('a news item published in the editor is found within 30 seconds, whatever hamza, ta marbuta and article', async ({ page, app, adminPassword }) => {
        test.setTimeout(150_000);
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');

        const published = Date.now();
        const id = await publishedNews(page, 'ارتفاع أسعار الشَّركة الوطنية للكهرباء', 'قفزت أسعار الكهرباء في نابلس.');
        const waited = await until(page.request, app.url, 'اسعار الشركه', id, true);
        expect((Date.now() - published) / 1000 - waited).toBeLessThan(30);

        for (const q of ['أسعار الشركة', 'الشركة', 'شركة', 'والشركة', 'للكهرباء', 'كهرباء', 'الكهرباء نابلس']) {
            expect(ids(await search(page.request, app.url, q)), q).toContain(id);
        }
        const hit = (await search(page.request, app.url, 'الشركه')).data.posts[0];
        expect(hit.highlight.title).toBe('ارتفاع أسعار <mark>الشَّركة</mark> الوطنية للكهرباء');
        expect((await search(page.request, app.url, 'الشركه')).data.engine).toBe('meilisearch');
    });

    test('a scheduled item enters search when its time comes, not before', async ({ page, app, adminPassword }) => {
        test.setTimeout(150_000);
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');
        const id = await createPost(page, 'خبر', 'موازنة البلدية الجديدة');
        await fillRequired(page, 'تفاصيل الموازنة.');
        await save(page);

        // Scheduled a few seconds ahead (the editor allows a minute at least; the rule is not what is tested here).
        app.sql(`UPDATE posts SET status = 'scheduled', published_at = now() + interval '4 seconds' WHERE id = ${id}`);
        app.artisan(['search:sync-due']);
        expect(ids(await search(page.request, app.url, 'موازنة'))).not.toContain(id);

        await new Promise((r) => setTimeout(r, 5_000));
        const due = Date.now();
        app.artisan(['posts:publish-due']);   // the scheduler's every-minute task (the delayed job does the same on a real queue)
        await until(page.request, app.url, 'موازنه البلديه', id, true);
        expect((Date.now() - due) / 1000).toBeLessThan(30);
    });

    test('a synonym added on the panel finds an item that does not contain the word typed', async ({ page, app, adminPassword }) => {
        test.setTimeout(150_000);
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');
        const id = await publishedNews(page, 'هبوط سعر الشيكل أمام الدولار');
        await until(page.request, app.url, 'الشيكل', id, true);
        expect(ids(await search(page.request, app.url, 'الشيقل'))).not.toContain(id);

        await page.goto(panel('/synonyms/create'));
        const words = page.locator('input[id="form.terms"]');
        for (const word of ['شيكل', 'شيقل']) {
            await words.fill(word);
            await words.press('Enter');
        }
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await page.waitForURL(/synonyms\/\d+\/edit/);

        await until(page.request, app.url, 'الشيقل', id, true, 15);

        // The same word again, in another spelling, is refused on the field.
        await page.goto(panel('/synonyms/create'));
        for (const word of ['الشيكل', 'عملة']) {
            await words.fill(word);
            await words.press('Enter');
        }
        await page.getByRole('button', { name: 'إضافة', exact: true }).click();
        await expect(page.getByText('موجودة في مجموعة أخرى')).toBeVisible();
        expect(app.sql('SELECT count(*) FROM synonym_groups')).toBe('1');
    });

    test('hiding a character takes its cards out of search, showing it brings them back', async ({ page, app, adminPassword }) => {
        test.setTimeout(180_000);
        await signIn(page, app, adminPassword);
        const media = await uploadImage(page, 'cover.png', 'صورة');
        const character = Number(app.sql("INSERT INTO characters (name_ar, slug, role_ar, accent_color) VALUES ('المستشار', 'advisor', 'ينصح', '#1F6F5B') RETURNING id"));
        app.sql(`INSERT INTO character_poses (character_id, pose_type_code, static_media_id) VALUES (${character}, 'explaining', ${media})`);

        const card = await createPost(page, 'بطاقة توعية', 'ادّخر قبل الأعياد');
        await fillRequired(page, 'نصيحة: ادّخر جزءاً من الدخل.');
        await pick(page, 'awareness.character_id', 'المستشار');
        await pick(page, 'awareness.character_pose_id', 'تشرح');
        await pick(page, 'awareness.awareness_type_code', 'نصيحة');
        await page.getByRole('button', { name: 'نشر الآن' }).click();
        await afterLivewire(page, () => page.getByRole('button', { name: 'تأكيد' }).click(), 'callMountedAction');
        await until(page.request, app.url, 'الأعياد', card, true);

        const visibility = async (shown: boolean) => {
            await page.goto(panel(`/characters/${character}/edit`));
            const toggle = page.getByRole('switch', { name: 'ظاهرة في الموقع' });
            if ((await toggle.getAttribute('aria-checked')) !== String(shown)) await toggle.click();
            await save(page);
        };

        await visibility(false);
        await until(page.request, app.url, 'الأعياد', card, false, 10);
        expect(ids(await search(page.request, app.url, 'ادخر'))).toEqual([]);

        await visibility(true);
        await until(page.request, app.url, 'الأعياد', card, true, 10);
    });

    test('drafts never reach search, and nothing in an answer carries the engine key', async ({ page, app, adminPassword }) => {
        test.setTimeout(120_000);
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'cover.png', 'صورة');
        const draft = await createPost(page, 'خبر', 'مسودة سرية عن الذهب');
        await fillRequired(page, 'لم تنشر بعد.');
        await save(page);
        const live = await publishedNews(page, 'سعر الذهب اليوم');
        await until(page.request, app.url, 'الذهب', live, true);

        const answer = await page.request.get(`${app.url}/api/v1/search?q=${encodeURIComponent('الذهب')}`);
        const text = await answer.text();
        expect(ids(JSON.parse(text))).toEqual([live]);
        expect(ids(JSON.parse(text))).not.toContain(draft);
        expect(text).not.toContain(process.env.E2E_MEILI_KEY!);
        expect(JSON.stringify(answer.headers())).not.toContain(process.env.E2E_MEILI_KEY!);
        expect(app.log()).not.toContain(process.env.E2E_MEILI_KEY!);
    });
});

// This test runs an engine of its own and stops it; the shared one keeps serving the other tests.
const withOwnEngine = test.extend<{ engine: Meili }>({
    engine: async ({}, use) => {
        const engine = await startMeili();
        try {
            await use(engine);
        } finally {
            engine.stop();
        }
    },
    appOptions: async ({ engine }, use) => use({ search: engine }),
});

withOwnEngine('when the engine stops, search keeps answering from the database and saving still works', async ({ page, app, adminPassword, engine }) => {
    test.setTimeout(180_000);
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await publishedNews(page, 'إقبال على شراء الذهب', 'المحال مزدحمة.');
    await until(page.request, app.url, 'الذهب', id, true);
    expect((await search(page.request, app.url, 'الذهب')).data.engine).toBe('meilisearch');

    engine.stop();   // the engine dies

    const answer = await search(page.request, app.url, 'اقبال الذهب');
    expect(answer.data.engine).toBe('database');
    expect(ids(answer)).toEqual([id]);

    // An editor publishes meanwhile: the save is not held up by the engine.
    const second = await publishedNews(page, 'الذهب يتراجع مساءً', 'هدوء في السوق.');
    expect(app.sql(`SELECT status FROM posts WHERE id = ${second}`)).toBe('published');
    expect(ids(await search(page.request, app.url, 'الذهب', { sort: 'latest' }))).toEqual([second, id]);
    expect(app.log()).toContain('Search engine unavailable');
});

test.describe('the panel', () => {
    test('quick search finds drafts, pictures and writers, whatever the hamza', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        await uploadImage(page, 'farm.png', 'مزرعة الطاقة الشمسية');
        await createPost(page, 'خبر', 'أسعار الطاقة الشمسية');
        app.sql("INSERT INTO authors (name, slug) VALUES ('سلمى الأحمد', 'salma')");

        const box = page.getByPlaceholder('بحث').first();
        const lookFor = async (q: string, expected: string) => {
            await box.fill(q);
            await expect(page.locator('.fi-global-search-results-ctn').getByText(expected).first()).toBeVisible();
        };
        await page.goto(panel(''));
        await lookFor('اسعار الطاقه', 'أسعار الطاقة الشمسية');
        await lookFor('مزرعه', 'farm.png');
        await lookFor('الاحمد', 'سلمى الأحمد');
    });

    test('the search statistics show what was searched, never an e-mail or a phone number', async ({ page, app, adminPassword }) => {
        await signIn(page, app, adminPassword);
        for (const q of ['أسعار الذهب', 'أسعار الذهب', 'كلمة لا توجد', 'someone@example.com', '0599123456']) {
            await page.request.get(`${app.url}/api/v1/search?${new URLSearchParams({ q, track: '1' })}`);
        }

        await page.goto(panel('/search-statistics'));
        await expect(page.getByText('اسعار الذهب').first()).toBeVisible();
        await expect(page.getByText('كلمه لا توجد').first()).toBeVisible();
        await expect(page.getByText('example.com')).toHaveCount(0);
        await expect(page.getByText('0599123456')).toHaveCount(0);
        expect(app.dump()).not.toContain('someone@example.com');
    });
});
