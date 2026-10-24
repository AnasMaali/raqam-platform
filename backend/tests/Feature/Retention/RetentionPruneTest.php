<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function retainedConversation(int $daysAgo): int
{
    $id = DB::table('bot_conversations')->insertGetId(['session_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)),
        'started_at' => DB::raw("now() - make_interval(days => {$daysAgo})")]);
    DB::table('bot_messages')->insert([
        ['conversation_id' => $id, 'role' => 'user', 'content' => 'سؤال', 'created_at' => DB::raw("now() - make_interval(days => {$daysAgo})")],
        ['conversation_id' => $id, 'role' => 'bot', 'content' => 'جواب', 'created_at' => DB::raw("now() - make_interval(days => {$daysAgo})")],
    ]);

    return $id;
}

function retainedContactMessage(int $daysAgo): int
{
    return DB::table('contact_messages')->insertGetId(['name' => 'زائر', 'email' => 'visitor@example.com', 'subject' => 'سؤال',
        'message' => 'نص الرسالة', 'created_at' => DB::raw("now() - make_interval(days => {$daysAgo})")]);
}

test('chatbot conversations go after 180 days with their messages; younger ones stay', function () {
    $old = retainedConversation(181);
    $edge = retainedConversation(179);
    $new = retainedConversation(0);

    $this->artisan('retention:prune')->assertSuccessful();

    expect(DB::table('bot_conversations')->orderBy('id')->pluck('id')->all())->toBe([$edge, $new])
        ->and(DB::table('bot_messages')->where('conversation_id', $old)->count())->toBe(0)
        ->and(DB::table('bot_messages')->whereIn('conversation_id', [$edge, $new])->count())->toBe(4);
});

test('contact messages go after 365 days; younger ones stay, whatever their status', function () {
    $old = retainedContactMessage(366);
    $edge = retainedContactMessage(364);
    $new = retainedContactMessage(0);
    DB::table('contact_messages')->where('id', $edge)->update(['status' => 'archived']);

    $this->artisan('retention:prune')->assertSuccessful();

    expect(DB::table('contact_messages')->orderBy('id')->pluck('id')->all())->toBe([$edge, $new])
        ->and($old)->toBeLessThan($edge);
});

test('the periods come from the configuration', function () {
    config(['raqam.retention.chat_conversations_days' => 10, 'raqam.retention.contact_messages_days' => 20]);
    $chatOld = retainedConversation(11);
    $chatNew = retainedConversation(9);
    $contactOld = retainedContactMessage(21);
    $contactNew = retainedContactMessage(19);

    $this->artisan('retention:prune')->assertSuccessful()->expectsOutputToContain('Deleted 1 chatbot conversations');

    expect(DB::table('bot_conversations')->pluck('id')->all())->toBe([$chatNew])
        ->and(DB::table('contact_messages')->pluck('id')->all())->toBe([$contactNew])
        ->and([$chatOld, $contactOld])->not->toBeEmpty();
});

test('the defaults are 180 days for conversations, 365 for contact messages and 90 for forged payment notifications', function () {
    expect(config('raqam.retention'))->toBe(['chat_conversations_days' => 180, 'contact_messages_days' => 365, 'forged_payment_events_days' => 90]);
});

test('retention:prune runs every night', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'retention:prune'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('40 2 * * *');
});

test('spent and expired e-mail codes go after a day, and notifications with a forged signature after 90 days (phase 10 review F4)', function () {
    $code = fn (string $created, string $expires, ?string $consumed = null) => DB::table('verification_codes')->insertGetId(['purpose' => 'account_verify',
        'email' => 'a@example.com', 'code_hash' => 'h', 'created_at' => DB::raw("now() - interval '{$created}'"), 'expires_at' => DB::raw("now() + interval '{$expires}'"),
        'consumed_at' => $consumed ? DB::raw("now() - interval '{$consumed}'") : null]);
    $expiredLong = $code('2 days', '-47 hours');            // expired more than a day ago
    $expiredLately = $code('130 minutes', '-2 hours');      // expired, but less than a day ago
    $spentLong = $code('2 days', '1 hour', '25 hours');     // used more than a day ago (still unexpired on paper)
    $fresh = $code('5 minutes', '5 minutes');
    ensure('payment_gateways', ['code' => 'fake', 'name_ar' => 'بوابة تجريبية']);
    $event = fn (string $id, bool $valid, string $age) => DB::table('payment_events')->insertGetId(['gateway_code' => 'fake', 'gateway_event_id' => $id,
        'event_type' => 't', 'signature_valid' => $valid, 'payload' => '{}', 'received_at' => DB::raw("now() - interval '{$age}'")]);
    $forgedOld = $event('invalid-1', false, '91 days');
    $forgedNew = $event('invalid-2', false, '89 days');
    $realOld = $event('ev-1', true, '400 days');

    $this->artisan('retention:prune')->assertSuccessful();
    expect(DB::table('verification_codes')->orderBy('id')->pluck('id')->all())->toBe([$expiredLately, $fresh])
        ->and(DB::table('payment_events')->orderBy('id')->pluck('id')->all())->toBe([$forgedNew, $realOld]);
});
