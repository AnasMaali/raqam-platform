import { test, expect, panel, signIn } from '../support/fixtures';
import { sitePost } from '../support/mail';

// Phase 8 screens on a phone (Pixel 7, 412 px): nothing sticks out sideways, and the bell opens.

const noSideScroll = async (page: import('@playwright/test').Page, where: string) => {
    const wider = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, `${where} is wider than the screen by ${wider}px`).toBeLessThanOrEqual(1);
};

test('messages, templates, the e-mail log, notifications, newsletter and chatbot screens fit a phone', async ({ page, app }) => {
    test.setTimeout(240_000);
    app.artisan(['bot:install']);
    await sitePost(page, app, '/contact', { name: 'منى عبد الرحمن', email: 'mona@example.com', subject: 'سؤال طويل عن أسعار الإعلانات في الصفحة الرئيسية', message: 'نص '.repeat(200), turnstile_token: 'pass:contact' });
    await sitePost(page, app, '/chat/messages', { message: 'سؤال بلا جواب عن موضوع غريب جداً' });
    app.sql(`INSERT INTO subscribers (email, status, confirmed_at, source_placement, source_path) VALUES ('reader@example.com', 'active', now(), 'post_end', '/news/1/عنوان-طويل-جداً-لخبر')`);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    const message = app.sql('SELECT id FROM contact_messages');

    for (const [path, text, where] of [
        ['/contact-messages', 'منى عبد الرحمن', 'inbox'],
        [`/contact-messages/${message}`, 'mona@example.com', 'a message'],
        ['/email-templates', 'رمز الدخول إلى اللوحة', 'templates'],
        ['/email-templates/visitor_contact_reply/edit', 'المتغيرات المتاحة', 'template form'],
        ['/email-log', 'mona@example.com', 'e-mail log'],
        ['/notifications', 'رسالة جديدة من منى', 'notifications'],
        ['/subscribers', 'reader@example.com', 'subscribers'],
        ['/newsletter-issues', 'تجهيز مسودة', 'newsletters'],
        ['/bot-entries', 'كيف أعلن معكم؟', 'knowledge base'],
        ['/bot-entries/create', 'صيغ أخرى للسؤال', 'entry form'],
        ['/bot-buttons', 'العملات اليوم', 'buttons'],
        ['/faqs', 'سؤال جديد', 'FAQ'],
        ['/bot-questions', 'حوّله لجواب', 'unanswered'],
        ['/settings', 'يوم تجهيز النشرة الأسبوعية', 'settings'],
    ] as const) {
        await page.goto(panel(path));
        await expect(page.locator('.fi-main').getByText(text).first()).toBeVisible();
        await noSideScroll(page, where);
    }

    await page.goto(panel(''));
    await page.locator('[data-bell]').click();
    await expect(page.locator('.fi-dropdown-panel:visible').getByText('رسالة جديدة من منى')).toBeVisible();
    await noSideScroll(page, 'bell');
});
