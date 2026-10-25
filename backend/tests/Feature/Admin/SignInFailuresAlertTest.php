<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/* 24.15: many failed sign-ins to the panel alert the admins (bell + e-mail), at most once an hour. */

beforeEach(function () {
    config(['mail.default' => 'array']);
    $this->admin = staffAdmin('owner@raqam.example');
});

function failedSignIns(int $count, string $ago = '10 minutes', int $accounts = 2): void
{
    foreach (range(1, $count) as $i) {
        DB::insert("INSERT INTO login_attempts (email, succeeded, failure_reason, attempted_at) VALUES (?, false, 'bad_credentials', now() - ?::interval)",
            ['victim'.($i % $accounts).'@example.com', $ago]);
    }
}

test('twenty failures in the last hour raise one security alert with the count, by bell and e-mail', function () {
    failedSignIns(20);
    DB::insert("INSERT INTO login_attempts (email, user_id, succeeded, attempted_at) VALUES ('owner@raqam.example', ?, true, now())", [$this->admin]);

    $this->artisan('auth:watch-failures')->expectsOutputToContain('Failed sign-ins in the last hour: 20.')->assertSuccessful();
    $this->artisan('auth:watch-failures')->assertSuccessful();   // within the hour: no second alert

    $alerts = DB::table('admin_notifications')->where('category', 'security')->get();
    expect($alerts)->toHaveCount(1)->and($alerts[0]->title)->toContain('20')->and($alerts[0]->body)->toContain('2 بريد')
        ->and(collect(sentEmailsTo('owner@raqam.example'))->filter(fn ($m) => str_contains((string) $m->getSubject(), '20 محاولة دخول فاشلة'))->count())->toBe(1);
});

test('fewer failures, or failures older than an hour, raise nothing', function () {
    failedSignIns(19);
    failedSignIns(30, '2 hours');

    $this->artisan('auth:watch-failures')->expectsOutputToContain('Failed sign-ins in the last hour: 19.')->assertSuccessful();

    expect(DB::table('admin_notifications')->where('category', 'security')->count())->toBe(0);
});

test('the scheduler looks every quarter hour', function () {
    $task = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'auth:watch-failures'));

    expect($task?->expression)->toBe('*/15 * * * *');
});
