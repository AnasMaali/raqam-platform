import { test, expect, signIn } from '../support/fixtures';
import { createPost, fillRequired, uploadImage, afterLivewire } from '../support/panel';

test('the secret preview link shows a draft without signing in, and stops working when it expires', async ({ page, browser, app, adminPassword }) => {
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await createPost(page, 'خبر', 'خبر قيد الكتابة');
    await fillRequired(page, 'هذا نص المسودة السري.');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await expect(page.getByText('تم الحفظ').first()).toBeVisible();

    // Ask for a 24-hour link: it appears in a notification.
    await page.getByRole('button', { name: 'معاينة' }).click();
    await afterLivewire(page, () => page.getByRole('dialog').getByRole('button', { name: 'إنشاء الرابط' }).click(), 'callMountedAction');
    const link = (await page.locator('.fi-no-notification').filter({ hasText: 'رابط المعاينة' }).innerText()).match(/https?:\/\/\S+\/preview\/\d+\?\S+/)![0];

    // A stranger with the link (a browser that never signed in) sees the draft, marked private.
    const stranger = await browser.newContext();
    const opened = await stranger.newPage();
    const response = await opened.goto(link);
    expect(response!.status()).toBe(200);
    await expect(opened.getByRole('heading', { name: 'خبر قيد الكتابة' })).toBeVisible();
    await expect(opened.getByText('هذا نص المسودة السري.')).toBeVisible();
    await expect(opened.getByText(/معاينة خاصة/)).toBeVisible();
    expect(response!.headers()['x-robots-tag']).toContain('noindex');
    expect(response!.headers()['cache-control']).toContain('no-store');

    // But it is not public: the API still says the item does not exist, and nothing else of the panel opened.
    expect((await stranger.request.get(`${app.url}/api/v1/posts/${id}`)).status()).toBe(404);

    // The signature is the key: changing the id, the expiry or the signature gives nothing.
    const url = new URL(link);
    for (const tampered of [
        link.replace(`/preview/${id}`, `/preview/${id + 1}`),
        (() => { const u = new URL(link); u.searchParams.set('expires', String(Number(u.searchParams.get('expires')) + 86_400)); return u.toString(); })(),
        (() => { const u = new URL(link); u.searchParams.set('signature', 'a'.repeat(64)); return u.toString(); })(),
        link.replace(/&signature=[^&]+/, ''),
    ]) {
        expect([403, 404], tampered).toContain((await stranger.request.get(tampered, { maxRedirects: 0 })).status());
    }
    expect(url.pathname).toBe(`/preview/${id}`);

    // A link made three days ago for 24 hours was valid once, and is refused now.
    const expired = app.artisan(['tinker', '--execute', `Illuminate\\Support\\Carbon::setTestNow(now()->subDays(3)); echo app(App\\Content\\PostPreview::class)->url(App\\Models\\Post::find(${id}), 24);`]).trim().split('\n').pop()!;
    expect(expired).toContain('/preview/');
    expect((await stranger.request.get(expired, { maxRedirects: 0 })).status()).toBe(403);
    await stranger.close();
});
