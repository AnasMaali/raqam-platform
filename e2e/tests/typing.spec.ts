import { test, expect, panel, signIn } from '../support/fixtures';
import { publishedNews, uploadImage, writeBody, save } from '../support/panel';
import { ADMIN_EMAIL } from '../support/app';

// A short idle limit, so the session really ends while someone is typing.
test.use({ appOptions: { env: { ADMIN_IDLE_SECONDS: '40', ADMIN_WARN_SECONDS: '15' } } });

test('the session ends while typing; after signing in again the editor is back with the text', async ({ page, app, adminPassword }) => {
    test.setTimeout(180_000);
    await signIn(page, app, adminPassword);
    await uploadImage(page, 'cover.png', 'صورة');
    const id = await publishedNews(page, 'خبر طويل', 'البداية.');   // published: autosave never touches it, only the browser copy protects the text

    // Type a long passage and then go quiet. Only requests the server sees count as activity; typing is not one.
    await writeBody(page, ' وهذه فقرة طويلة كتبها المحرر ولم يحفظها قبل انتهاء الجلسة.');
    const typed = 'وهذه فقرة طويلة كتبها المحرر ولم يحفظها قبل انتهاء الجلسة.';
    await page.waitForTimeout(2500);   // the browser copy is made every two seconds

    await page.waitForURL(/login\?expired=1/, { timeout: 90_000 });
    await expect(page.getByText('انتهت الجلسة، سجّل الدخول من جديد.')).toBeVisible();
    expect(app.sql(`SELECT body FROM posts WHERE id = ${id}`)).not.toContain(typed);   // the server never had it

    // Sign in again: a second code within a minute is refused by design, so clear the limits as an operator would.
    app.artisan(['admin:unlock', ADMIN_EMAIL]);
    await signIn(page, app, adminPassword);

    // The panel takes the editor back to the page it was on, and offers the copy it kept.
    await page.waitForURL(new RegExp(`posts/${id}/edit`), { timeout: 20_000 });
    await expect(page.getByText(/وجدنا نسخة محلية/)).toBeVisible();
    await page.getByRole('button', { name: 'استرجاع', exact: true }).click();
    await expect(page.locator('.tiptap.ProseMirror').first()).toContainText(typed);

    await save(page);
    expect(app.sql(`SELECT body FROM posts WHERE id = ${id}`)).toContain(typed);
    expect(app.sql("SELECT string_agg(new_value, ',') FROM audit_log_changes WHERE field = 'logout_reason'")).toContain('idle');
});
