import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire } from '../support/panel';
import { sitePost } from '../support/mail';

// Phase 8: the chatbot through the site's route, and its knowledge base in the panel.
const ask = async (page: import('@playwright/test').Page, app: import('../support/app').App, message: string, ip = '203.0.113.7') =>
    sitePost(page, app, '/chat/messages', { message, path: '/' }, ip);

test('answers, live numbers, no answer with WhatsApp, then the unanswered question becomes an answer in the panel', async ({ page, app }) => {
    test.setTimeout(240_000);
    app.artisan(['bot:install']);
    app.sql(`UPDATE settings SET value = '+970599123456' WHERE key = 'contact.whatsapp'`);
    await app.artisanAsync(['currency:fetch']);

    const config = await (await page.request.get(`${app.url}/api/v1/chat`)).json();
    expect(config.data.buttons.map((b: { label: string }) => b.label)).toEqual(['الخدمات والأسعار', 'أعلن معنا', 'العملات اليوم', 'الطقس', 'تواصل معنا']);

    const advertise = await (await ask(page, app, 'بدي اعلن عندكم')).json();
    expect(advertise.data.reply.kind).toBe('answer');
    expect(advertise.data.reply.html).toContain('href="/advertise"');

    const dollar = await (await ask(page, app, 'كم سعر الدولار اليوم؟')).json();
    expect(dollar.data.reply.kind).toBe('live');
    expect(dollar.data.reply.html).toContain('دولار');

    const button = config.data.buttons.find((b: { label: string }) => b.label === 'تواصل معنا');
    const contact = await (await sitePost(page, app, '/chat/messages', { button: button.id, conversation: dollar.data.conversation })).json();
    expect(contact.data.conversation).toBe(dollar.data.conversation);
    expect(contact.data.reply.whatsapp).toMatch(/^https:\/\/wa\.me\/970599123456\?text=/);

    const unknown = await (await ask(page, app, 'هل عندكم تدريب صيفي للطلاب؟')).json();
    expect(unknown.data.reply.kind).toBe('no_answer');
    expect(decodeURIComponent(unknown.data.reply.whatsapp)).toContain('سؤالي: هل عندكم تدريب صيفي للطلاب؟');

    // Links and code are refused and not kept; personal data is not kept.
    expect((await ask(page, app, 'شوف https://evil.example')).status()).toBe(422);
    expect((await ask(page, app, '<script>alert(1)</script>')).status()).toBe(422);
    await ask(page, app, 'رقمي 0599123456 وبريدي a@b.example كيف أعلن');
    const stored = app.sql(`SELECT string_agg(content, ' | ') FROM bot_messages WHERE role = 'user'`);
    expect(stored).not.toContain('0599123456');
    expect(stored).not.toContain('a@b.example');
    expect(stored).not.toContain('evil.example');

    // The panel: the unanswered question becomes an answer in one step.
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/bot-questions'));
    await expect(page.getByText('الأسئلة: ').first()).toBeVisible();
    const row = page.locator('tr').filter({ hasText: 'هل عندكم تدريب صيفي للطلاب؟' });
    await row.getByRole('button', { name: 'حوّله لجواب' }).click();
    const dialog = page.locator('.fi-modal-window:visible');
    await dialog.locator('.tiptap.ProseMirror').click();
    await dialog.locator('.tiptap.ProseMirror').pressSequentially('نعم، عندنا تدريب صيفي. <script>x</script>', { delay: 5 });
    await afterLivewire(page, () => dialog.getByRole('button', { name: 'إرسال' }).click(), 'callMountedAction');
    await expect(page.locator('tr').filter({ hasText: 'هل عندكم تدريب صيفي للطلاب؟' })).toHaveCount(0);
    expect(app.sql(`SELECT count(*) FROM bot_entries WHERE question = 'هل عندكم تدريب صيفي للطلاب؟'`)).toBe('1');

    const answered = await (await ask(page, app, 'عندكم تدريب صيفي؟', '198.51.100.30')).json();
    expect(answered.data.reply.kind).toBe('answer');
    expect(answered.data.reply.html).toContain('عندنا تدريب صيفي');
    expect(answered.data.reply.html).not.toContain('<script');
});

test('the knowledge base and the FAQ are edited from the panel and answer at once', async ({ page, app }) => {
    test.setTimeout(180_000);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/faqs/create'));
    await page.locator('[id="form.question"]').fill('هل المحتوى مجاني؟');
    await page.locator('[id="form.answer"]').fill('نعم، كل ما ننشره مجاني.');
    await afterLivewire(page, () => page.locator('button[type="submit"]').filter({ hasText: 'إضافة' }).click(), 'create');

    const faq = await (await ask(page, app, 'هل المحتوى مجاني')).json();
    expect(faq.data.reply.html).toContain('كل ما ننشره مجاني');
    expect((await (await page.request.get(`${app.url}/api/v1/faqs`)).json()).data[0].question).toBe('هل المحتوى مجاني؟');
});
