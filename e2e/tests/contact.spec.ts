import { test, expect, panel, signIn } from '../support/fixtures';
import { mailsTo, sitePost } from '../support/mail';
import { ADMIN_EMAIL } from '../support/app';

// Phase 8: the contact form as the site's server sends it, and the inbox of the panel.
const form = (over: Record<string, unknown> = {}) => ({
    name: 'سامي الخطيب', email: 'sami@example.com', phone: '00970 599 123 456', subject: 'استفسار عن الإعلان',
    message: 'مرحباً،\nأريد أن أعلن <b>عندكم</b>.', turnstile_token: 'pass:contact', website: '', ...over,
});

test('a message reaches the inbox and the admin; the sender gets one reply; robots and failed checks are turned away', async ({ page, app, outside }) => {
    test.setTimeout(180_000);
    const sent = await sitePost(page, app, '/contact', form());
    expect(sent.status()).toBe(202);
    expect(await sent.json()).toEqual({ data: { received: true } });

    // Turnstile is asked by our server, with the visitor's address; solved for another form or site, or not at all = refused.
    expect(outside.requests.some((r) => r.path === '/turnstile/siteverify' && new URLSearchParams(r.body).get('remoteip') === '203.0.113.7')).toBe(true);
    for (const token of ['fail', 'pass:newsletter', 'elsewhere:contact']) {
        const r = await sitePost(page, app, '/contact', form({ turnstile_token: token, email: 'other@example.com' }));
        expect(r.status()).toBe(422);
    }
    // The hidden field: the same answer, nothing kept.
    expect((await sitePost(page, app, '/contact', form({ website: 'https://spam.example', email: 'bot@example.com' }))).status()).toBe(202);
    // Not from the site: refused.
    expect((await page.request.post(`${app.url}/api/v1/contact`, { data: form() })).status()).toBe(403);
    expect(app.sql('SELECT count(*) FROM contact_messages')).toBe('1');

    // The same mailbox from another visitor: kept, but no second automatic reply.
    expect((await sitePost(page, app, '/contact', form({ subject: 'رسالة ثانية', name: 'منى' }), '198.51.100.9')).status()).toBe(202);
    const replies = mailsTo(app, 'sami@example.com');
    expect(replies).toHaveLength(1);
    expect(replies[0].subject).toBe('وصلتنا رسالتك');
    expect(replies[0].text).toContain('مرحباً سامي الخطيب');
    expect(replies[0].html).toContain('dir="rtl"');

    // The admin: an e-mail per message (reply goes to the sender), and the bell.
    const alerts = mailsTo(app, ADMIN_EMAIL).filter((m) => m.subject.startsWith('رسالة جديدة'));
    expect(alerts.map((m) => m.subject).sort()).toEqual(['رسالة جديدة: استفسار عن الإعلان', 'رسالة جديدة: رسالة ثانية']);
    expect(alerts[0].html).toContain('&lt;b&gt;عندكم&lt;/b&gt;');

    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await expect(page.locator('[data-bell] .fi-badge, [data-bell] [class*="badge"]').first()).toHaveText('2');
    await page.locator('[data-bell]').click();
    await page.getByText('رسالة جديدة من سامي الخطيب').first().click();
    await page.waitForURL(/contact-messages\/\d+/);
    // The visitor's words as text, never as HTML.
    await expect(page.getByText('أريد أن أعلن <b>عندكم</b>.')).toBeVisible();
    await expect(page.getByText('+970599123456')).toBeVisible();
    await expect(page.getByRole('link', { name: 'رد بالبريد' })).toHaveAttribute('href', /^mailto:sami%40example\.com\?subject=/);

    // Opened = read; archived from the list.
    await page.goto(panel('/contact-messages'));
    await expect(page.locator('tr').filter({ hasText: 'رسالة ثانية' })).toContainText('جديدة');
    const first = page.locator('tr').filter({ hasText: 'استفسار عن الإعلان' });
    await expect(first).toContainText('مقروءة');
    await first.getByRole('button', { name: 'أرشفة' }).click();
    await expect(first).toContainText('مؤرشفة');
});

test('a visitor sends five messages an hour at most; the sixth is refused', async ({ page, app }) => {
    for (let i = 1; i <= 5; i++) {
        expect((await sitePost(page, app, '/contact', form({ email: `p${i}@example.com` }), '2001:db8:1:2::' + i)).status()).toBe(202);
    }
    const sixth = await sitePost(page, app, '/contact', form({ email: 'p6@example.com' }), '2001:db8:1:2::ff');
    expect(sixth.status()).toBe(429);
    expect((await sixth.json()).error.code).toBe('too_many');
});
