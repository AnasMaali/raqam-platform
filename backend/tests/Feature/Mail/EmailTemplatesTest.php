<?php

use App\Mail\EmailCatalog;
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
        // The dormant ones (20.5) are made switched off.
        ->and(DB::table('email_templates')->where('key', 'booking_received')->value('is_active'))->toBeFalse()
        ->and(DB::table('email_templates')->where('key', 'visitor_contact_reply')->value('is_active'))->toBeTrue();

    EmailTemplates::save('visitor_contact_reply', 'عنوان معدّل', '<p>نص معدّل {الاسم}</p>', true, null);
    $this->artisan('emails:install')->assertSuccessful();

    expect(DB::table('email_templates')->where('key', 'visitor_contact_reply')->first(['subject', 'body_html']))
        ->toEqual((object) ['subject' => 'عنوان معدّل', 'body_html' => '<p>نص معدّل {الاسم}</p>']);
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
    EmailTemplates::ensure('booking_received');

    expect(SystemMail::queue(new TemplatedEmail('booking_received', ['الاسم' => 'س']), 'c@example.com'))->toBeNull()
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
