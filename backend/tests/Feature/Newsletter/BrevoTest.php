<?php

use App\Jobs\SyncSubscriberToBrevo;
use App\Newsletter\BrevoClient;
use App\Newsletter\BrevoUnavailable;
use App\Newsletter\NewsletterLinks;
use App\Newsletter\Subscribers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => NEWSLETTER_SITE_TOKEN, 'raqam.auth.code_key' => 'code-key', 'raqam.newsletter.timebox_ms' => 1]);
    Cache::flush();
    brevoOn();
    fakeOutside();
});

function activeSubscriber(string $email = 'reader@example.com'): int
{
    return DB::table('subscribers')->insertGetId(['email' => $email, 'name' => 'قارئ', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);
}

function webhook(array $body, ?string $token = null)
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Brevo-Token' => $token ?? str_repeat('w', 40)])->postJson('/api/v1/newsletter/brevo-webhook', $body);
}

test('a confirmed subscriber is added to the Brevo list with the key in a header and their own unsubscribe link', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    newsletterCall('confirm', ['challenge' => $challenge, 'code' => newsletterCode()])->assertOk();
    $id = DB::table('subscribers')->value('id');

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://brevo.test/v3/contacts'
        && $r->hasHeader('api-key', 'xkeysib-test-KEY-123456') && ! str_contains($r->url(), 'xkeysib')
        && $r['email'] === 'reader@example.com' && $r['listIds'] === [3] && $r['updateEnabled'] === true && $r['emailBlacklisted'] === false
        && $r['attributes']['FIRSTNAME'] === 'قارئ' && str_starts_with($r['attributes']['RAQAM_UNSUB'], "https://raqam.example/newsletter/unsubscribe?s={$id}&t="));
});

test('an unsubscribe takes the contact out of the list and blocks it in Brevo', function () {
    $id = activeSubscriber();
    expect(app(Subscribers::class)->unsubscribe($id, NewsletterLinks::signature($id, 'reader@example.com')))->toBeTrue();

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://brevo.test/v3/contacts/reader%40example.com?identifierType=email_id'
        && $r['unlinkListIds'] === [3] && $r['emailBlacklisted'] === true);
});

test('deleting a subscriber from the panel removes the row, its codes and the Brevo contact; the audit keeps no address', function () {
    $id = activeSubscriber();
    DB::table('verification_codes')->insert(['purpose' => 'newsletter_confirm', 'email' => 'reader@example.com', 'code_hash' => 'x', 'expires_at' => now()->addHour()]);

    app(Subscribers::class)->delete($id, null);

    expect(DB::table('subscribers')->count())->toBe(0)->and(DB::table('verification_codes')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('subject_table', 'subscribers')->value('action'))->toBe('delete')
        ->and(DB::table('audit_log_changes')->pluck('new_value')->implode(' '))->not->toContain('reader@example.com');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/contacts/reader%40example.com'));
});

test('without a Brevo key nothing is ever sent to Brevo, and subscribing still works', function () {
    config(['raqam.newsletter.brevo.key' => null]);
    $challenge = subscribeRequest()->json('data.challenge');
    newsletterCall('confirm', ['challenge' => $challenge, 'code' => newsletterCode()])->assertOk();

    expect(DB::table('subscribers')->value('status'))->toBe('active');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'brevo'));
});

test('the webhook needs the secret header: a wrong one, none, or none configured is a 404 and changes nothing', function (?string $configured, ?string $sent) {
    config(['raqam.newsletter.brevo.webhook_token' => $configured]);
    activeSubscriber();

    webhook(['event' => 'unsubscribe', 'email' => 'reader@example.com'], $sent ?? '')->assertNotFound();
    expect(DB::table('subscribers')->value('status'))->toBe('active');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'brevo'));
})->with([
    'wrong token' => [str_repeat('w', 40), 'guess'],
    'no token' => [str_repeat('w', 40), null],
    'nothing configured' => [null, ''],
]);

test('a webhook is only a hint: when Brevo still has the contact in the list, nothing changes (a forged message)', function () {
    activeSubscriber();
    webhook(['event' => 'unsubscribe', 'email' => 'reader@example.com'])->assertNoContent();

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_contains($r->url(), '/contacts/reader%40example.com'));
    expect(DB::table('subscribers')->value('status'))->toBe('active');
});

test('when Brevo agrees, the subscriber is unsubscribed, bounced, or gone', function (?array $contact, string $event, string $status) {
    activeSubscriber();
    fakeOutside($contact);

    webhook(['event' => $event, 'email' => 'READER@example.com'])->assertNoContent();

    expect(DB::table('subscribers')->value('status'))->toBe($status);
})->with([
    'unsubscribed (blocked)' => [['emailBlacklisted' => true, 'listIds' => []], 'unsubscribe', 'unsubscribed'],
    'out of our list' => [['emailBlacklisted' => false, 'listIds' => [9]], 'unsubscribe', 'unsubscribed'],
    'unsubscribed from our list' => [['emailBlacklisted' => false, 'listIds' => [3, 9], 'listUnsubscribed' => [3]], 'unsubscribe', 'unsubscribed'],
    'hard bounce' => [['emailBlacklisted' => true, 'listIds' => [3]], 'hard_bounce', 'bounced'],
    'spam complaint' => [['emailBlacklisted' => true, 'listIds' => [3]], 'spam', 'unsubscribed'],
    'deleted in Brevo' => [null, 'contact_deleted', 'unsubscribed'],
]);

test('a batch is read, odd items are skipped, and one address is checked at most once a minute', function () {
    activeSubscriber();
    activeSubscriber('second@example.com');

    webhook([
        ['event' => 'unsubscribe', 'email' => 'reader@example.com'], ['event' => 'unsubscribe', 'email' => 'reader@example.com'],
        ['event' => 'opened', 'email' => 'second@example.com'], ['event' => 'unsubscribe', 'email' => 'not an address'], 'garbage',
    ])->assertNoContent();

    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count())->toBe(1);
});

test('a Brevo failure is a short Arabic reason without the key, counted in provider health; the third alerts the admins', function () {
    staffAdmin('admin@raqam.example');
    fakeOutside(brevoStatus: 401);

    foreach (range(1, 3) as $i) {
        expect(fn () => app(BrevoClient::class)->contact('reader@example.com'))->toThrow(BrevoUnavailable::class, 'رفض Brevo الطلب: 401');
    }

    $health = DB::table('provider_health')->where('provider_code', 'brevo')->first();
    expect($health->consecutive_failures)->toBe(3)->and($health->last_error)->not->toContain('xkeysib')
        ->and(DB::table('admin_notifications')->where('dedupe_key', 'provider:brevo')->count())->toBe(1)
        ->and(file_get_contents(storage_path('logs/testing.log')))->not->toContain('xkeysib-test-KEY-123456');
});

test('a failed sync is retried by the queue, and the job reads the row when it runs', function () {
    $id = activeSubscriber();
    DB::table('subscribers')->where('id', $id)->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

    (new SyncSubscriberToBrevo('reader@example.com'))->handle(app(BrevoClient::class));

    // The row says unsubscribed now, so the late job unsubscribes instead of re-adding.
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r['emailBlacklisted'] === true);
    Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://brevo.test/v3/contacts');
    expect((new SyncSubscriberToBrevo('x@example.com'))->tries)->toBe(5);
});
