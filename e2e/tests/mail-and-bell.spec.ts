import { test, expect, panel, signIn } from '../support/fixtures';
import { afterLivewire, save } from '../support/panel';
import { ADMIN_EMAIL } from '../support/app';
import { mailsTo, sitePost } from '../support/mail';

// Phase 8: editing a message template, the e-mail log, and the bell.
const alert = (app: import('../support/app').App, key: string, title: string, path = 'contact-messages') =>
    app.artisan(['tinker', `--execute=App\\Panel\\AdminAlerts::raise('system', '${key}', '${title}', 'تفاصيل', '${path}');`]);

test('a template is edited with its variables; an unknown one is refused; the next message uses the new words', async ({ page, app }) => {
    test.setTimeout(240_000);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    await page.goto(panel('/email-templates'));
    await page.locator('tr').filter({ hasText: 'رد تلقائي على «اتصل بنا»' }).getByRole('link', { name: 'تعديل' }).click();
    await page.waitForURL(/email-templates\/visitor_contact_reply\/edit/);
    await expect(page.getByText('المتغيرات المتاحة: {الاسم}')).toBeVisible();

    // An unknown variable: refused under the field, the old text stays.
    const editor = page.locator('.tiptap.ProseMirror').first();
    await editor.click();
    await page.keyboard.press('Control+A');
    await editor.pressSequentially('أهلاً {الاسم}، كلمة السر {كلمة_السر}', { delay: 5 });
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ التغييرات' }).click(), 'save');
    await expect(page.getByText('متغير غير معروف: {كلمة_السر}')).toBeVisible();

    await editor.click();
    await page.keyboard.press('Control+A');
    await editor.pressSequentially('أهلاً {الاسم}، استلمنا رسالتك وسنرد خلال يومين.', { delay: 5 });
    await page.locator('[id="form.subject"]').fill('شكراً {الاسم}');
    await save(page);

    // The preview shows made-up values; "send me a test" mails the admin.
    await page.getByRole('button', { name: 'معاينة' }).click();
    await expect(page.locator('.fi-modal-window:visible').getByText('أهلاً سامي، استلمنا رسالتك')).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'إرسال تجربة لي' }).click();
    await afterLivewire(page, () => page.locator('.fi-modal-window:visible').getByRole('button', { name: 'تأكيد' }).click(), 'callMountedAction');
    await expect.poll(() => mailsTo(app, ADMIN_EMAIL).some((m) => m.subject === 'شكراً سامي')).toBe(true);

    // A visitor whose name looks like a variable gets it as typed: values are filled once and escaped.
    await sitePost(page, app, '/contact', { name: 'ليلى <i>{الرمز}</i>', email: 'layla@example.com', subject: 'سؤال', message: 'نص الرسالة', turnstile_token: 'pass:contact' });
    const reply = mailsTo(app, 'layla@example.com')[0];
    expect(reply.subject).toBe('شكراً ليلى <i>{الرمز}</i>');
    expect(reply.html).toContain('أهلاً ليلى &lt;i&gt;{الرمز}&lt;/i&gt;، استلمنا رسالتك');

    // The e-mail log lists both.
    await page.goto(panel('/email-log'));
    await expect(page.getByText('layla@example.com')).toBeVisible();
    await expect(page.locator('tr').filter({ hasText: 'layla@example.com' })).toContainText('أُرسلت');

    // The sign-in code cannot lose its code.
    await page.goto(panel('/email-templates/admin_login_code/edit'));
    await editor.click();
    await page.keyboard.press('Control+A');
    await editor.pressSequentially('ادخل الآن', { delay: 5 });
    await afterLivewire(page, () => page.getByRole('button', { name: 'حفظ التغييرات' }).click(), 'save');
    await expect(page.getByText('المتغير {الرمز} إجباري')).toBeVisible();
    expect(app.sql(`SELECT body_html FROM email_templates WHERE key = 'admin_login_code'`)).toContain('{الرمز}');
});

test('the bell counts, lists and opens alerts, marks them read, and never asks the server on its own', async ({ page, app }) => {
    test.setTimeout(180_000);
    await signIn(page, app, process.env.E2E_ADMIN_PASSWORD!);
    alert(app, 'disk', 'مساحة السيرفر قاربت على النفاد', 'notifications');
    alert(app, 'contact:9', 'رسالة جديدة من منى', 'contact-messages');
    alert(app, 'disk', 'مساحة السيرفر قاربت على النفاد');   // the same alert within the hour: not again
    await page.reload();

    const bell = page.locator('[data-bell]');
    await expect(bell).toContainText('2');

    // Idle on the page for 20 seconds: no request at all (it would keep the session alive).
    const requests: string[] = [];
    page.on('request', (r) => requests.push(r.url()));
    await page.waitForTimeout(20_000);
    expect(requests.filter((u) => u.includes('/livewire/'))).toEqual([]);

    await bell.click();
    await page.locator('.fi-dropdown-panel:visible').getByText('رسالة جديدة من منى').click();
    await page.waitForURL(/contact-messages$/);
    await expect(page.locator('[data-bell]')).toContainText('1');

    await page.locator('[data-bell]').click();
    await afterLivewire(page, () => page.getByRole('button', { name: 'تعليم الكل كمقروء' }).click());
    await page.reload();
    await expect(page.locator('[data-bell] [class*="badge"]')).toHaveCount(0);

    await page.goto(panel('/notifications'));
    await expect(page.locator('.fi-main').getByText('مساحة السيرفر قاربت على النفاد')).toBeVisible();
    await expect(page.locator('.fi-main').getByText('رسالة جديدة من منى')).toBeVisible();
});
