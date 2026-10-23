<?php

use App\Jobs\SendSystemEmail;
use App\Mail\AdminLoginCode;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
});

function reply(): TemplatedEmail
{
    return new TemplatedEmail('visitor_contact_reply', ['الاسم' => 'سامي']);
}

test('a queued message is logged first, then marked sent with its try (20.8, 20.9)', function () {
    Queue::fake();
    $id = SystemMail::queue(reply(), 'sami@example.com');

    expect(DB::table('email_log')->where('id', $id)->first(['template_key', 'recipient', 'status', 'attempts']))
        ->toEqual((object) ['template_key' => 'visitor_contact_reply', 'recipient' => 'sami@example.com', 'status' => 'queued', 'attempts' => 0]);
    Queue::assertPushed(SendSystemEmail::class, fn (SendSystemEmail $job) => $job->logId === $id && $job->tries === 3 && $job->backoff === [30, 120]);
});

test('each try is counted, and after the third the message is failed with a reason that hides the SMTP password', function () {
    config(['mail.mailers.smtp.password' => 'smtp-PaSsWoRd24680']);
    failingMailer('535 authentication failed for user x with password smtp-PaSsWoRd24680');
    Queue::fake();
    $id = SystemMail::queue(reply(), 'sami@example.com');
    $job = Queue::pushed(SendSystemEmail::class)->first();

    foreach ([1, 2, 3] as $try) {
        $fake = new FakeJob;
        $fake->attempts = $try;
        $job->setJob($fake);
        expect(fn () => $job->handle())->toThrow(Exception::class);
        expect(DB::table('email_log')->where('id', $id)->value('attempts'))->toBe($try)
            ->and(DB::table('email_log')->where('id', $id)->value('status'))->toBe('queued');
    }
    $job->failed(new RuntimeException('535 authentication failed for user x with password smtp-PaSsWoRd24680'));

    $row = DB::table('email_log')->where('id', $id)->first();
    expect($row->status)->toBe('failed')->and($row->attempts)->toBe(3)
        ->and($row->error)->toContain('535')->not->toContain('smtp-PaSsWoRd24680')->toContain('[hidden]');
});

test('a try that works after failures ends as sent, with no error left', function () {
    failingMailer(failures: 1);
    Queue::fake();
    $id = SystemMail::queue(reply(), 'sami@example.com');
    $job = Queue::pushed(SendSystemEmail::class)->first();

    $first = new FakeJob;
    $job->setJob($first);
    expect(fn () => $job->handle())->toThrow(Exception::class);
    $second = new FakeJob;
    $second->attempts = 2;
    $job->setJob($second);
    $job->handle();

    expect(DB::table('email_log')->where('id', $id)->first(['status', 'attempts', 'error']))->toEqual((object) ['status' => 'sent', 'attempts' => 2, 'error' => null])
        ->and(DB::table('email_log')->where('id', $id)->value('sent_at'))->not->toBeNull();
});

test('a queued message is only sent after the transaction that made it commits', function () {
    try {
        DB::transaction(function () {
            SystemMail::queue(reply(), 'sami@example.com');
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(sentEmails())->toBe([])->and(DB::table('email_log')->count())->toBe(0);

    DB::transaction(fn () => SystemMail::queue(reply(), 'sami@example.com'));
    expect(sentEmails())->toHaveCount(1);
});

test('the sign-in code never waits for the queue: sent at once, tried three times, logged', function () {
    Queue::fake();
    failingMailer(failures: 2);
    config(['raqam.mail.direct_pause_ms' => 0]);

    expect(SystemMail::sendNow(new AdminLoginCode('123456', 10), 'admin@raqam.example'))->toBeTrue();

    Queue::assertNothingPushed();
    expect(app('test.failing-transport')->tries)->toBe(3)
        ->and(DB::table('email_log')->first(['template_key', 'status', 'attempts']))->toEqual((object) ['template_key' => 'admin_login_code', 'status' => 'sent', 'attempts' => 3]);
});

test('a sign-in message that fails three times is logged as failed, and queue() sends it directly too', function () {
    Queue::fake();
    failingMailer();
    config(['raqam.mail.direct_pause_ms' => 0]);

    expect(SystemMail::sendNow(new AdminLoginCode('123456', 10), 'admin@raqam.example'))->toBeFalse();
    SystemMail::queue(new AdminLoginCode('654321', 10), 'admin@raqam.example');

    Queue::assertNothingPushed();
    expect(DB::table('email_log')->pluck('status')->all())->toBe(['failed', 'failed'])
        ->and(DB::table('email_log')->pluck('attempts')->all())->toBe([3, 3])
        ->and(app('test.failing-transport')->tries)->toBe(6);
});

test('emails:prune deletes the log older than 90 days and keeps the rest', function () {
    $old = SystemMail::queue(reply(), 'old@example.com');
    $edge = SystemMail::queue(reply(), 'edge@example.com');
    $new = SystemMail::queue(reply(), 'new@example.com');
    DB::table('email_log')->where('id', $old)->update(['queued_at' => DB::raw("now() - interval '91 days'")]);
    DB::table('email_log')->where('id', $edge)->update(['queued_at' => DB::raw("now() - interval '89 days'")]);

    $this->artisan('emails:prune')->assertSuccessful();

    expect(DB::table('email_log')->orderBy('id')->pluck('id')->all())->toBe([$edge, $new]);
});

test('emails:prune runs every day', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'emails:prune'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 2 * * *');
});

test('sending to every admin goes to the active staff only', function () {
    staffAdmin('a@raqam.example');
    staffAdmin('b@raqam.example');
    staffAdmin('off@raqam.example', ['is_active' => false]);
    staffAdmin('gone@raqam.example', ['deleted_at' => now()]);
    insertUser('customer', 'customer@example.com');

    expect(SystemMail::toAdmins(fn () => new TemplatedEmail('admin_disk_space', ['المساحة' => '1 GB', 'النسبة' => '5%'])))->toBe(2);
    expect(collect(sentEmails())->map(fn ($m) => $m->getTo()[0]->getAddress())->sort()->values()->all())->toBe(['a@raqam.example', 'b@raqam.example']);
});
