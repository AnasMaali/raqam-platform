<?php

use App\Mail\EmailCatalog;
use App\Mail\EmailCatalogVersions;
use App\Mail\EmailTemplates;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Mail\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
});

test('emails:install makes every template of the catalogue, and never overwrites an edited one', function () {
    $this->artisan('emails:install')->assertSuccessful();
    expect(DB::table('email_templates')->count())->toBe(count(EmailCatalog::templates()))
        // Each is made on or off as the catalogue says (a template marked `active => false` starts switched off).
        ->and(DB::table('email_templates')->pluck('is_active', 'key')->all())
        ->toEqual(array_map(fn (array $t) => $t['active'] ?? true, EmailCatalog::templates()));

    EmailTemplates::save('visitor_contact_reply', 'عنوان معدّل', '<p>نص معدّل {الاسم}</p>', true, null);
    $this->artisan('emails:install')->assertSuccessful();

    expect(DB::table('email_templates')->where('key', 'visitor_contact_reply')->first(['subject', 'body_html']))
        ->toEqual((object) ['subject' => 'عنوان معدّل', 'body_html' => '<p>نص معدّل {الاسم}</p>']);
});

// The status-update text as first shipped (with a link, switched off), before phase 10 changed it.
const OLD_STATUS_MAIL = ['تحديث على حجزك {المرجع}', '<p>مرحباً {الاسم}،</p><p>صارت حالة حجزك {المرجع}: {الحالة}.</p><p>{الرابط}</p>', false];

test('every template has the hash of its text of now at the end of its shipped versions, and no unknown template has any', function () {
    foreach (EmailCatalog::templates() as $key => $t) {
        $shipped = EmailCatalogVersions::HASHES[$key] ?? [];
        expect(end($shipped))->toBe(EmailCatalogVersions::hash($t['subject'], $t['body'], $t['active'] ?? true), "add this hash at the end of {$key}");
    }
    expect(array_keys(EmailCatalogVersions::HASHES))->toEqualCanonicalizing(array_keys(EmailCatalog::templates()))
        ->and(EmailCatalogVersions::HASHES['booking_status_changed'][0])->toBe(EmailCatalogVersions::hash(...OLD_STATUS_MAIL));
});

test('emails:install brings a row nobody edited up to the catalogue\'s text of now, once', function () {
    EmailTemplates::install();
    [$subject, $body, $active] = OLD_STATUS_MAIL;
    DB::table('email_templates')->where('key', 'booking_status_changed')
        ->update(['name_ar' => 'اسم قديم', 'subject' => $subject, 'body_html' => $body, 'is_active' => $active]);

    $this->artisan('emails:install')->assertSuccessful()->expectsOutput('Added 0 e-mail templates; updated 1 that nobody had edited.');

    $t = EmailCatalog::get('booking_status_changed');
    expect(DB::table('email_templates')->where('key', 'booking_status_changed')->first(['name_ar', 'subject', 'body_html', 'is_active', 'updated_by']))
        ->toEqual((object) ['name_ar' => $t['name'], 'subject' => $t['subject'], 'body_html' => $t['body'], 'is_active' => true, 'updated_by' => null])
        ->and(EmailTemplates::install())->toBe(['added' => 0, 'updated' => 0]);
});

test('an edited row is never brought up to date: not a changed text, not a template only switched on or off', function () {
    EmailTemplates::install();
    [$subject, $body] = OLD_STATUS_MAIL;
    // The old text, switched on by the admin: that is an edit too.
    DB::table('email_templates')->where('key', 'booking_status_changed')->update(['subject' => $subject, 'body_html' => $body, 'is_active' => true]);
    EmailTemplates::save('booking_received', 'عنوان معدّل {المرجع}', '<p>نص معدّل {الاسم}</p>', true, null);
    $before = DB::table('email_templates')->orderBy('key')->get();

    expect(EmailTemplates::install())->toBe(['added' => 0, 'updated' => 0])
        ->and(DB::table('email_templates')->orderBy('key')->get())->toEqual($before);
});

test('an edit saved between the read and the update of emails:install is kept', function () {
    EmailTemplates::install();
    [$subject, $body, $active] = OLD_STATUS_MAIL;
    DB::table('email_templates')->where('key', 'booking_status_changed')->update(['subject' => $subject, 'body_html' => $body, 'is_active' => $active]);
    $saved = false;
    DB::listen(function ($query) use (&$saved) {
        if (! $saved && str_starts_with($query->sql, 'select "key", "subject", "body_html", "is_active" from "email_templates"')) {
            $saved = true;   // the admin saves right after install() read the rows
            DB::table('email_templates')->where('key', 'booking_status_changed')->update(['subject' => 'عنوان الأدمن {المرجع}']);
        }
    });

    expect(EmailTemplates::install())->toBe(['added' => 0, 'updated' => 0])
        ->and($saved)->toBeTrue()
        ->and(DB::table('email_templates')->where('key', 'booking_status_changed')->value('subject'))->toBe('عنوان الأدمن {المرجع}');
});

test('a message is the RTL frame of Raqam around the template, with a plain-text copy', function () {
    SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'سامي']), 'sami@example.com');

    $mail = sentEmailsTo('sami@example.com')[0];
    expect($mail->getSubject())->toBe('وصلتنا رسالتك')
        ->and($mail->getHtmlBody())->toContain('dir="rtl"')->toContain('lang="ar"')->toContain('مرحباً سامي،')
        ->and($mail->getTextBody())->toContain('مرحباً سامي،')->not->toContain('<p>')
        ->and($mail->getFrom()[0]->getAddress())->toBe(config('mail.from.address'));
});

test('each sender address comes from its setting, or the default address when it is empty (20.1)', function () {
    DB::table('settings')->insert(['key' => 'mail.info', 'value' => 'info@raqam.example', 'value_type' => 'email']);

    SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'سامي']), 'sami@example.com');
    SystemMail::queue(new TemplatedEmail('admin_disk_space', ['المساحة' => '1 GB', 'النسبة' => '5%']), 'admin@raqam.example');

    expect(sentEmailsTo('sami@example.com')[0]->getFrom()[0]->getAddress())->toBe('info@raqam.example')
        ->and(sentEmailsTo('admin@raqam.example')[0]->getFrom()[0]->getAddress())->toBe(config('mail.from.address'));
});

test('what a visitor typed is escaped: it can never become HTML in the mailbox', function () {
    $html = TemplateRenderer::render('admin_contact_message', [
        'الاسم' => '<script>alert(1)</script>', 'الرسالة' => "<img src=x onerror=alert(1)>\nسطر ثانٍ", 'الموضوع' => '"><b>عريض</b>',
    ])['html'];

    expect($html)->not->toContain('<script>')->not->toContain('<img')->not->toContain('<b>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->toContain('&lt;img src=x onerror=alert(1)&gt;<br>')
        ->toContain('&quot;&gt;&lt;b&gt;');
});

test('a value that looks like a variable is not filled again (one pass only)', function () {
    // The visitor names himself after the code variable of another template, and after one of this template.
    $out = TemplateRenderer::render('admin_contact_message', ['الاسم' => '{الرمز} {البريد}', 'البريد' => 'real@example.com']);

    expect($out['html'])->toContain('الاسم: {الرمز} {البريد}')->toContain('البريد: real@example.com');
});

test('a value can never split the subject into two headers', function () {
    SystemMail::queue(new TemplatedEmail('admin_contact_message', ['الموضوع' => "مرحبا\r\nBcc: victim@example.com\r\nX-Evil: 1", 'الاسم' => 'س']), 'admin@raqam.example');

    $mail = sentEmailsTo('admin@raqam.example')[0];
    expect($mail->getSubject())->toBe('رسالة جديدة: مرحبا Bcc: victim@example.com X-Evil: 1')
        ->and($mail->getBcc())->toBe([])
        ->and($mail->getHeaders()->has('X-Evil'))->toBeFalse();
});

test('the code is shown large; a link variable becomes a link only for http(s)', function () {
    $code = TemplateRenderer::render('admin_login_code', ['الرمز' => '042137', 'الدقائق' => 10])['html'];
    $link = TemplateRenderer::render('admin_contact_message', ['الرابط' => 'https://raqam.example/x?a=1&b=2'])['html'];
    $evil = TemplateRenderer::render('admin_contact_message', ['الرابط' => 'javascript:alert(1)'])['html'];

    expect($code)->toContain('letter-spacing:8px;font-weight:bold">042137</span>')
        ->and($link)->toContain('<a href="https://raqam.example/x?a=1&amp;b=2"')
        ->and($evil)->not->toContain('<a href="javascript')->toContain('javascript:alert(1)');
});

test('saving a template cleans its HTML: no scripts, styles, images or event handlers', function () {
    EmailTemplates::save('visitor_contact_reply', 'شكراً', '<p onclick="x()" style="color:red">مرحباً {الاسم}<script>alert(1)</script><img src="https://x/y.png"></p><a href="javascript:alert(1)">رابط</a><a href="https://raqam.example">موقعنا</a>', true, null);

    $body = DB::table('email_templates')->where('key', 'visitor_contact_reply')->value('body_html');
    expect($body)->not->toContain('script')->not->toContain('onclick')->not->toContain('style=')->not->toContain('<img')->not->toContain('javascript:')
        ->toContain('مرحباً {الاسم}')->toContain('<a href="https://raqam.example" rel="noopener noreferrer">موقعنا</a>');
});

test('an unknown variable is refused, naming the ones on offer', function () {
    expect(fn () => EmailTemplates::save('visitor_contact_reply', 'شكراً {الرمز}', '<p>مرحباً {الاسم}</p>', true, null))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['subject'][0])->toContain('{الرمز}')->toContain('{الاسم}'));
    expect(fn () => EmailTemplates::save('visitor_contact_reply', 'شكراً', '<p>مرحباً {المجهول}</p>', true, null))
        ->toThrow(ValidationException::class, 'متغير غير معروف');
});

test('the sign-in code template cannot be saved without {الرمز}, nor switched off', function () {
    expect(fn () => EmailTemplates::save('admin_login_code', 'رمز الدخول', '<p>ادخل الآن</p>', true, null))
        ->toThrow(ValidationException::class, '{الرمز}');
    expect(fn () => EmailTemplates::save('admin_login_code', 'رمز الدخول', '<p>{الرمز}</p>', false, null))
        ->toThrow(ValidationException::class, 'لا يمكن إيقافها');

    EmailTemplates::save('admin_login_code', 'رمزك', '<p>الرمز: {الرمز}</p>', true, null);
    expect(DB::table('email_templates')->where('key', 'admin_login_code')->value('subject'))->toBe('رمزك');
});

test('a newline in the subject becomes a space when it is saved', function () {
    EmailTemplates::save('visitor_contact_reply', "سطر\r\nBcc: x@example.com", '<p>{الاسم}</p>', true, null);

    expect(DB::table('email_templates')->where('key', 'visitor_contact_reply')->value('subject'))->toBe('سطر Bcc: x@example.com');
});

test('a template edit is audited under the template name', function () {
    EmailTemplates::save('visitor_contact_reply', 'عنوان جديد', '<p>{الاسم}</p>', true, null);

    $fields = DB::table('audit_log_changes')->pluck('field')->all();
    expect($fields)->toContain('visitor_contact_reply.subject')->toContain('visitor_contact_reply.body_html');
});

test('a switched-off template sends nothing and logs nothing; a locked one always sends', function () {
    EmailTemplates::ensure('visitor_contact_reply');
    DB::table('email_templates')->where('key', 'visitor_contact_reply')->update(['is_active' => false]);

    expect(SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'س']), 'c@example.com'))->toBeNull()
        ->and(sentEmails())->toBe([])->and(DB::table('email_log')->count())->toBe(0);

    EmailTemplates::ensure('admin_login_code');
    DB::table('email_templates')->where('key', 'admin_login_code')->update(['is_active' => false]);   // written behind the screen's back
    SystemMail::sendNow(new TemplatedEmail('admin_login_code', ['الرمز' => '123456', 'الدقائق' => 10]), 'admin@raqam.example');
    expect(sentEmailsTo('admin@raqam.example'))->toHaveCount(1);
});

test('a template row is made the first time it is needed, so a message never waits for emails:install', function () {
    expect(DB::table('email_templates')->count())->toBe(0);

    SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'س']), 'a@example.com');

    expect(DB::table('email_templates')->pluck('key')->all())->toBe(['visitor_contact_reply'])
        ->and(DB::table('email_log')->value('status'))->toBe('sent');
});

test('no template lists the same shipped text twice', function () {
    foreach (EmailCatalogVersions::HASHES as $key => $hashes) {
        expect(array_unique($hashes))->toHaveCount(count($hashes), $key);
    }
});
