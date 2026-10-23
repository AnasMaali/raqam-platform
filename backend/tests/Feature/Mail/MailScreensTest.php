<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AdminNotifications\Pages\ListAdminNotifications;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\ContactMessages\Pages\ListContactMessages;
use App\Filament\Resources\ContactMessages\Pages\ViewContactMessage;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Livewire\AdminBell;
use App\Mail\EmailTemplates;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Models\ContactMessage;
use App\Models\User;
use App\Panel\AdminAlerts;
use App\Panel\ConnectionStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
    Filament::setCurrentPanel('admin');
    $this->user = User::factory()->staff()->create(['email' => 'me@raqam.example']);
    $this->actingAs($this->user);
});

function contactMessage(array $overrides = []): ContactMessage
{
    $id = DB::table('contact_messages')->insertGetId(array_merge(['name' => 'سامي', 'email' => 'sami@example.com', 'subject' => 'استفسار & سؤال', 'message' => "سطر\nسطر <b>ثانٍ</b>"], $overrides));

    return ContactMessage::query()->findOrFail($id);
}

test('the inbox lists the messages, newest first, with the number of new ones on the menu', function () {
    $old = contactMessage(['name' => 'قديم', 'created_at' => now()->subDay()]);
    $new = contactMessage(['name' => 'جديد']);
    contactMessage(['name' => 'مقروء', 'status' => 'read']);

    Livewire::test(ListContactMessages::class)->assertCanSeeTableRecords([$new, $old], inOrder: true);
    expect(ContactMessageResource::getNavigationBadge())->toBe('2');
});

test('opening a new message marks it read, by whom, in the audit trail; the visitor text stays escaped', function () {
    $m = contactMessage();

    Livewire::test(ViewContactMessage::class, ['record' => $m->id])->assertOk()
        ->assertSee('سطر <b>ثانٍ</b>', escape: true)->assertDontSee('<b>ثانٍ</b>', escape: false);

    expect($m->fresh()->only(['status', 'handled_by']))->toBe(['status' => 'read', 'handled_by' => $this->user->id])
        ->and(DB::table('audit_log_changes')->where('field', 'status')->first(['old_value', 'new_value']))->toEqual((object) ['old_value' => 'new', 'new_value' => 'read'])
        // The visitor's words never go into the audit trail.
        ->and(DB::table('audit_log_changes')->pluck('new_value')->implode(' '))->not->toContain('سطر');
});

test('a message is archived or deleted from the inbox', function () {
    $a = contactMessage();
    $b = contactMessage(['name' => 'آخر']);

    Livewire::test(ListContactMessages::class)->callAction(TestAction::make('archive')->table($a))->callAction(TestAction::make('delete')->table($b));

    expect($a->fresh()->status)->toBe('archived')->and(ContactMessage::query()->find($b->id))->toBeNull();
});

test('the reply button opens the mailbox with the address and subject encoded (nothing can be added to it)', function () {
    $m = contactMessage(['email' => 'sami@example.com', 'subject' => 'سؤال&bcc=spy@example.com']);

    expect(ContactMessageResource::replyUrl($m))->toBe('mailto:sami%40example.com?subject='.rawurlencode('رد: سؤال&bcc=spy@example.com'));
});

test('the templates screen lists every message and edits one', function () {
    Livewire::test(ListEmailTemplates::class)->assertOk()->assertSee('رمز الدخول إلى اللوحة')->assertSee('رد تلقائي على «اتصل بنا»');

    Livewire::test(EditEmailTemplate::class, ['record' => 'visitor_contact_reply'])
        ->assertSee('{الاسم}')
        ->fillForm(['subject' => 'شكراً {الاسم}', 'body_html' => '<p>أهلاً {الاسم}</p>', 'is_active' => true])
        ->call('save')->assertHasNoFormErrors();

    expect(DB::table('email_templates')->where('key', 'visitor_contact_reply')->value('subject'))->toBe('شكراً {الاسم}');
});

test('the screen refuses an unknown variable and a sign-in code template without the code, under the field', function () {
    Livewire::test(EditEmailTemplate::class, ['record' => 'visitor_contact_reply'])
        ->fillForm(['subject' => 'شكراً', 'body_html' => '<p>{كلمة_السر}</p>'])->call('save')->assertHasFormErrors(['body_html']);
    Livewire::test(EditEmailTemplate::class, ['record' => 'admin_login_code'])
        ->fillForm(['subject' => 'الدخول', 'body_html' => '<p>ادخل</p>'])->call('save')->assertHasFormErrors(['body_html']);

    expect(DB::table('email_templates')->where('key', 'admin_login_code')->value('body_html'))->toContain('{الرمز}');
});

test('a sign-in template cannot be switched off from the screen', function () {
    Livewire::test(EditEmailTemplate::class, ['record' => 'admin_login_code'])
        ->assertFormFieldIsDisabled('is_active')
        ->fillForm(['subject' => 'الدخول', 'body_html' => '<p>{الرمز}</p>', 'is_active' => false])->call('save');

    expect(DB::table('email_templates')->where('key', 'admin_login_code')->value('is_active'))->toBeTrue();
});

test('an address that is not a template of the catalogue is a 404, not an error', function (string $key) {
    $this->get(EditEmailTemplateUrl($key))->assertNotFound();
})->with(['unknown', '1', 'admin_login_code\' OR 1=1']);

function EditEmailTemplateUrl(string $key): string
{
    return '/'.Filament::getPanel('admin')->getPath().'/email-templates/'.rawurlencode($key).'/edit';
}

test('the preview shows the template with made-up values, and "send me a test" mails the admin', function () {
    Livewire::test(EditEmailTemplate::class, ['record' => 'admin_contact_message'])
        ->mountAction('preview')->assertMountedActionModalSee(['sami@example.com', 'هذه رسالة تجريبية.'])->assertMountedActionModalDontSeeHtml('<script');
    Livewire::test(EditEmailTemplate::class, ['record' => 'admin_contact_message'])->callAction('test');

    $mail = sentEmailsTo('me@raqam.example');
    expect($mail)->toHaveCount(1)->and($mail[0]->getSubject())->toBe('رسالة جديدة: استفسار');
});

test('the e-mail log lists who got what and whether it went', function () {
    SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'س']), 'sami@example.com');

    Livewire::test(ListEmailLogs::class)->assertOk()->assertSee('sami@example.com')->assertSee('رد تلقائي على «اتصل بنا»')->assertSee('أُرسلت');
});

test('the bell shows the unread count and the newest alerts; clicking one marks it read and opens it in the panel', function () {
    AdminAlerts::raise('messages', 'contact:1', 'رسالة <b>جديدة</b>', 'تفاصيل', 'contact-messages/1');
    AdminAlerts::raise('system', 'disk', 'مساحة السيرفر');
    $id = DB::table('admin_notifications')->where('user_id', $this->user->id)->where('dedupe_key', 'contact:1')->value('id');

    Livewire::test(AdminBell::class)->assertSee('مساحة السيرفر')->assertSee('رسالة &lt;b&gt;جديدة&lt;/b&gt;', escape: false)
        ->call('open', $id)->assertRedirect(url(Filament::getPanel('admin')->getPath().'/contact-messages/1'));

    expect(AdminAlerts::unreadCount($this->user->id))->toBe(1);
    Livewire::test(AdminBell::class)->call('readAll');
    expect(AdminAlerts::unreadCount($this->user->id))->toBe(0);
});

test('an alert never leads out of the panel', function (string $path) {
    expect(AdminBell::url($path))->toBeNull();
})->with(['//evil.example', 'https://evil.example', '../x', '/\\evil.example', 'javascript:alert(1)']);

test('the bell never asks the server on its own (no polling in the admin)', function () {
    expect(file_get_contents(resource_path('views/livewire/admin-bell.blade.php')))->not->toContain('wire:poll');
});

test('the bell is drawn on every panel page for a signed-in admin', function () {
    $this->get('/'.Filament::getPanel('admin')->getPath())->assertOk()->assertSee('data-bell', escape: false);
});

test('the notifications screen shows only this admin\'s alerts', function () {
    $other = User::factory()->staff()->create();
    DB::table('admin_notifications')->insert([
        ['user_id' => $this->user->id, 'category' => 'system', 'title' => 'لي'],
        ['user_id' => $other->id, 'category' => 'system', 'title' => 'لغيري'],
    ]);

    Livewire::test(ListAdminNotifications::class)->assertSee('لي')->assertDontSee('لغيري')->callAction('readAll');
    expect(DB::table('admin_notifications')->where('user_id', $other->id)->value('read_at'))->toBeNull();
});

test('the home shows the new messages and the unread alerts', function () {
    contactMessage(['name' => 'سامي', 'subject' => 'إعلان']);
    AdminAlerts::raise('system', 'disk', 'مساحة السيرفر قاربت على النفاد');

    Livewire::test(Dashboard::class)->assertSee('رسائل جديدة (1)')->assertSee('سامي — إعلان')->assertSee('التنبيهات (1 غير مقروءة)')->assertSee('مساحة السيرفر قاربت على النفاد');
});

test('the e-mail connection reads its state from the log: unknown, working, failing', function () {
    config(['mail.default' => 'smtp']);
    $state = fn () => collect(ConnectionStatus::all(false))->firstWhere('name', 'البريد');
    expect($state()['state'])->toBe(ConnectionStatus::UNKNOWN);

    $id = DB::table('email_log')->insertGetId(['template_key' => EmailTemplates::find('visitor_contact_reply')->key, 'recipient' => 'a@example.com', 'status' => 'sent', 'sent_at' => now()]);
    expect($state()['state'])->toBe(ConnectionStatus::OK)->and(implode(' ', $state()['lines']))->toContain('آخر إرسال ناجح');

    DB::table('email_log')->insert(['template_key' => 'visitor_contact_reply', 'recipient' => 'b@example.com', 'status' => 'failed', 'attempts' => 3, 'error' => '535 authentication failed']);
    expect($state()['state'])->toBe(ConnectionStatus::FAILING)->and(implode(' ', $state()['lines']))->toContain('535 authentication failed')->toContain('فشل آخر 24 ساعة: 1');

    config(['mail.default' => 'log']);
    expect($state()['state'])->toBe(ConnectionStatus::OFF);
});
