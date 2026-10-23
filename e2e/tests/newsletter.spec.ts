import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, publishedNews, uploadImage } from '../support/panel';
import { codeFor, mailsTo, sitePost } from '../support/mail';

// Phase 8: the newsletter, from the site's form to Brevo and back.
const WEBHOOK = { 'X-Raqam-Brevo-Token': 'e2e-fake-brevo-webhook-token-0123456789abcdef' };
const subscribe = (page: import('@playwright/test').Page, app: import('../support/app').App, email: string, ip = '203.0.113.7') =>
    sitePost(page, app, '/newsletter/subscribe', { email, name: 'قارئ', placement: 'post_end', path: '/news/7/خبر', turnstile_token: 'pass:newsletter' }, ip);

test('subscribe with the code, reach Brevo, unsubscribe from the own link; a forged webhook changes nothing, a true one does', async ({ page, app, outside }) => {
    test.setTimeout(240_000);
    const asked = await subscribe(page, app, 'reader@example.com');
    expect(asked.status()).toBe(202);
    const { challenge } = (await asked.json()).data;
    const code = await codeFor(app, 'reader@example.com');
    expect(mailsTo(app, 'reader@example.com')[0].subject).toBe('رمز تأكيد اشتراكك في نشرة رَقَم');
    expect(app.sql('SELECT count(*) FROM subscribers')).toBe('0');   // nothing kept before the code

    // A wrong code counts; the right one subscribes.
    const wrong = await sitePost(page, app, '/newsletter/confirm', { challenge, code: code === '000000' ? '111111' : '000000' });
    expect((await wrong.json()).error.code).toBe('bad_code');
    expect((await sitePost(page, app, '/newsletter/confirm', { challenge, code })).status()).toBe(200);
    expect(app.sql(`SELECT status || ' ' || source_placement || ' ' || source_path FROM subscribers`)).toBe('active post_end /news/7/خبر');

    // Brevo got the contact in our list, with the key in a header only and the subscriber's own unsubscribe link.
    const added = outside.brevo().find((r) => r.method === 'POST' && r.path === '/brevo/v3/contacts')!;
    expect(added.headers['api-key']).toBe('e2e-fake-brevo-key');
    const contact = JSON.parse(added.body);
    expect(contact.listIds).toEqual([3]);
    const link = new URL(contact.attributes.RAQAM_UNSUB);
    expect(link.origin + link.pathname).toBe('https://raqam.test/newsletter/unsubscribe');

    // The same answer for an address that is already subscribed: a challenge, and a code to its owner only.
    const again = await subscribe(page, app, 'reader@example.com', '198.51.100.20');
    expect(Object.keys((await again.json()).data).sort()).toEqual(['challenge', 'expires_in', 'resend_after']);

    // A forged unsubscribe (Brevo says the contact is still in the list): nothing changes.
    outside.answers.contacts['reader@example.com'] = { emailBlacklisted: false, listIds: [3] };
    expect((await page.request.post(`${app.url}/api/v1/newsletter/brevo-webhook`, { data: { event: 'unsubscribe', email: 'reader@example.com' } })).status()).toBe(404);
    expect((await page.request.post(`${app.url}/api/v1/newsletter/brevo-webhook`, { headers: WEBHOOK, data: { event: 'unsubscribe', email: 'reader@example.com' } })).status()).toBe(204);
    expect(app.sql('SELECT status FROM subscribers')).toBe('active');

    // The own link: unsubscribed here and blocked in Brevo.
    const unsub = await sitePost(page, app, '/newsletter/unsubscribe', { subscriber: link.searchParams.get('s'), signature: link.searchParams.get('t') });
    expect(unsub.status()).toBe(200);
    expect(app.sql('SELECT status FROM subscribers')).toBe('unsubscribed');
    const blocked = outside.brevo().find((r) => r.method === 'PUT' && r.path.startsWith('/brevo/v3/contacts/reader%40example.com'))!;
    expect(JSON.parse(blocked.body)).toEqual({ unlinkListIds: [3], emailBlacklisted: true });

    // Subscribed again, then Brevo reports a hard bounce that its API confirms.
    await page.waitForTimeout(61_000);
    const second = (await (await subscribe(page, app, 'reader@example.com', '198.51.100.21')).json()).data.challenge;
    expect((await sitePost(page, app, '/newsletter/confirm', { challenge: second, code: await codeFor(app, 'reader@example.com') })).status()).toBe(200);
    outside.answers.contacts['reader@example.com'] = { emailBlacklisted: true, listIds: [3] };
    await page.request.post(`${app.url}/api/v1/newsletter/brevo-webhook`, { headers: WEBHOOK, data: [{ event: 'hard_bounce', email: 'reader@example.com' }] });
    expect(app.sql('SELECT status FROM subscribers')).toBe('bounced');
});

test('the weekly draft goes to Brevo as a draft only; the admin edits it and reads the numbers; subscribers are exported', async ({ page, app, outside }) => {
    test.setTimeout(300_000);
    app.sql(`INSERT INTO subscribers (email, name, status, confirmed_at, source_placement) VALUES ('a@example.com', '=HYPERLINK("x")', 'active', now(), 'footer')`);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await uploadImage(page, 'cover.png', 'صورة');
    await publishedNews(page, 'خبر النشرة الأسبوعية');

    await page.goto(panel('/newsletter-issues'));
    await page.getByRole('button', { name: 'تجهيز مسودة هذا الأسبوع الآن' }).click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'تأكيد' }).click();
    await page.waitForURL(/newsletter-issues\/\d+\/edit/);

    const created = outside.brevo().find((r) => r.method === 'POST' && r.path === '/brevo/v3/emailCampaigns')!;
    const campaign = JSON.parse(created.body);
    expect(campaign.recipients).toEqual({ listIds: [3] });
    expect(campaign.scheduledAt).toBeUndefined();
    expect(campaign.htmlContent).toContain('خبر النشرة الأسبوعية');
    expect(campaign.htmlContent).toContain('href="https://raqam.test/news/');
    expect(outside.brevo().some((r) => /send|schedule/i.test(r.path))).toBe(false);

    await page.locator('[id="form.subject"]').fill('نشرة رَقَم: أهم أخبار الأسبوع');
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ التغييرات' }).click(), 'save');
    await expect.poll(() => outside.brevo().filter((r) => r.method === 'PUT' && r.path === '/brevo/v3/emailCampaigns/77').length).toBe(1);

    // Brevo sent it: the numbers, and the newsletter is marked sent.
    outside.answers.campaign = { status: 'sent', delivered: 120, uniqueViews: 60, uniqueClicks: 15 };
    await page.getByRole('button', { name: 'الأرقام من Brevo' }).click();
    await expect(page.locator('.fi-modal-window:visible')).toContainText('فتح: 60');
    await expect(page.locator('.fi-modal-window:visible')).toContainText('نقر: 15');
    expect(app.sql('SELECT status FROM newsletter_issues')).toBe('sent');

    // Subscribers: the numbers and the Excel export.
    await page.goto(panel('/subscribers'));
    await expect(page.getByText('مشتركون الآن: 1')).toBeVisible();
    await page.getByRole('button', { name: 'تصدير Excel' }).click();
    const download = page.waitForEvent('download');
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'تنزيل' }).click();
    expect((await download).suggestedFilename()).toMatch(/^subscribers-\d{4}-\d\d-\d\d\.xlsx$/);
});
