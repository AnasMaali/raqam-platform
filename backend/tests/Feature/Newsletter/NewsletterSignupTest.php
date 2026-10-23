<?php

use App\Newsletter\NewsletterLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => NEWSLETTER_SITE_TOKEN, 'raqam.auth.code_key' => 'code-key',
        'raqam.turnstile.secret' => 'turnstile-secret', 'raqam.turnstile.hostnames' => 'raqam.ps', 'raqam.newsletter.timebox_ms' => 1]);
    Cache::flush();
    fakeOutside();
});

function confirmCode(string $challenge, ?string $code = null, string $ip = '203.0.113.7')
{
    return newsletterCall('confirm', ['challenge' => $challenge, 'code' => $code ?? newsletterCode()], $ip);
}

test('asking to subscribe gives a challenge and mails a 6-digit code; nothing about the person is kept yet', function () {
    $r = subscribeRequest()->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
    validateAgainstPath($r->json(), '/newsletter/subscribe', 'post', 202);

    expect($r->json('data.resend_after'))->toBe(60)->and($r->json('data.expires_in'))->toBe(1800)
        ->and(newsletterCode())->toMatch('/^\d{6}$/')
        ->and(DB::table('subscribers')->count())->toBe(0)
        // Only an HMAC of the code is stored, never the code.
        ->and(DB::table('verification_codes')->value('code_hash'))->not->toContain(newsletterCode())
        ->and($r->json('data.challenge'))->not->toContain('reader@example.com');
});

test('the right code subscribes, with the consent proof: when, which page, which form (19.9)', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    $r = confirmCode($challenge)->assertOk()->assertExactJson(['data' => ['confirmed' => true]]);
    validateAgainstPath($r->json(), '/newsletter/confirm', 'post', 200);

    $s = DB::table('subscribers')->first();
    expect($s->email)->toBe('reader@example.com')->and($s->name)->toBe('قارئ')->and($s->status)->toBe('active')
        ->and($s->source_path)->toBe('/news/7/خبر')->and($s->source_placement)->toBe('post_end')
        ->and($s->consent_ip_hash)->toMatch('/^[0-9a-f]{64}$/')->and(json_encode($s))->not->toContain('203.0.113.7')
        ->and(abs(strtotime($s->subscribed_at) - time()))->toBeLessThan(60)->and(strtotime($s->confirmed_at))->toBeGreaterThanOrEqual(strtotime($s->subscribed_at));
});

test('the answer is the same for an address that is subscribed and one that is not', function () {
    DB::table('subscribers')->insert(['email' => 'reader@example.com', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);

    $known = subscribeRequest()->assertStatus(202)->json('data');
    $unknown = subscribeRequest(['email' => 'new@example.com'], '198.51.100.1')->assertStatus(202)->json('data');

    expect(array_keys($known))->toBe(array_keys($unknown))->and($known['resend_after'])->toBe($unknown['resend_after'])
        ->and(newsletterCode())->not->toBeNull()->and(newsletterCode('new@example.com'))->not->toBeNull();
});

test('a wrong code counts; after five wrong ones even the right code is refused', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    $right = newsletterCode();
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        confirmCode($challenge, $wrong)->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    }
    confirmCode($challenge, $right)->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    expect(DB::table('subscribers')->count())->toBe(0);
});

test('a code is good only with the challenge it was sent for', function () {
    $mine = subscribeRequest()->json('data.challenge');
    $code = newsletterCode();
    $this->travel(61)->seconds();
    // Somebody else asks for the same address: a new code (to its owner) and a new challenge; the old pair no longer works.
    $theirs = subscribeRequest([], '198.51.100.9')->json('data.challenge');

    confirmCode($theirs, $code)->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    confirmCode($mine, $code)->assertStatus(422);
    confirmCode($theirs, newsletterCode())->assertOk();
});

test('a code works only with its own challenge, even for the same address', function () {
    $mine = subscribeRequest()->json('data.challenge');
    // Within the minute a second request for the address gets a challenge but no new code.
    $theirs = subscribeRequest([], '198.51.100.9')->json('data.challenge');
    expect(newsletterCodesSent())->toBe(1);

    confirmCode($theirs)->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    confirmCode($mine)->assertOk();
});

test('a code expires after 30 minutes, and a forged or tampered challenge is refused', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    $code = newsletterCode();

    newsletterCall('confirm', ['challenge' => 'not-a-challenge', 'code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'expired');
    newsletterCall('confirm', ['challenge' => substr($challenge, 0, -4).'AAAA', 'code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'expired');

    // The code's 30 minutes are counted by the database clock.
    DB::table('verification_codes')->update(['created_at' => DB::raw("now() - interval '31 minutes'"), 'expires_at' => DB::raw("now() - interval '1 minute'")]);
    confirmCode($challenge, $code)->assertStatus(422)->assertJsonPath('error.code', 'expired');

    // And the challenge itself stops working after the code's time (and a margin).
    $this->travel(36)->minutes();
    newsletterCall('resend', ['challenge' => $challenge])->assertStatus(422)->assertJsonPath('error.code', 'expired');
    expect(DB::table('subscribers')->count())->toBe(0);
});

test('a code is used once, written by the database clock even when it stepped back', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    $code = newsletterCode();
    // The clock stepped back between the code and its use.
    DB::table('verification_codes')->update(['created_at' => DB::raw("now() + interval '2 seconds'"), 'expires_at' => DB::raw("now() + interval '30 minutes'")]);

    confirmCode($challenge, $code)->assertOk();
    confirmCode($challenge, $code)->assertStatus(422);

    $row = DB::table('verification_codes')->first();
    expect($row->consumed_at)->not->toBeNull()->and(strtotime($row->consumed_at))->toBeGreaterThanOrEqual(strtotime($row->created_at));
});

test('resend sends a new code only after a minute; the answer is the same either way', function () {
    $challenge = subscribeRequest()->json('data.challenge');
    $first = newsletterCode();

    newsletterCall('resend', ['challenge' => $challenge])->assertStatus(202)->assertJsonPath('data.challenge', $challenge);
    expect(newsletterCodesSent())->toBe(1);

    $this->travel(61)->seconds();
    newsletterCall('resend', ['challenge' => $challenge])->assertStatus(202);
    expect(newsletterCodesSent())->toBe(2);
    confirmCode($challenge, $first)->assertStatus(422);   // the new code replaced the old one
    confirmCode($challenge)->assertOk();
});

test('one address gets at most 3 codes an hour and 6 a day, whoever asks; beyond that the same answer and nothing sent', function () {
    foreach (range(1, 5) as $i) {
        subscribeRequest([], "198.51.100.{$i}")->assertStatus(202);
        $this->travel(61)->seconds();
    }
    expect(newsletterCodesSent())->toBe(3);

    $this->travel(1)->hours();
    foreach (range(6, 10) as $i) {
        subscribeRequest([], "198.51.100.{$i}")->assertStatus(202);
        $this->travel(61)->seconds();
    }
    expect(newsletterCodesSent())->toBe(6);

    $this->travel(2)->hours();
    subscribeRequest([], '198.51.100.99')->assertStatus(202);
    expect(newsletterCodesSent())->toBe(6);

    $this->travel(1)->days();
    subscribeRequest([], '198.51.100.100')->assertStatus(202);
    expect(newsletterCodesSent())->toBe(7)
        // The limit keys hold an HMAC, never the address.
        ->and(cacheStoreKeys())->not->toBeEmpty()
        ->and(implode(' ', cacheStoreKeys()))->not->toContain('reader');
});

test('a visitor may ask 10 times an hour; an IPv6 visitor is its /64', function () {
    foreach (range(1, 10) as $i) {
        subscribeRequest(['email' => "r{$i}@example.com"], '2001:db8:5:6::'.$i)->assertStatus(202);
        $this->travel(7)->seconds();
    }
    subscribeRequest(['email' => 'r11@example.com'], '2001:db8:5:6::ff')->assertStatus(429)->assertJsonPath('error.code', 'too_many');
    subscribeRequest(['email' => 'r12@example.com'], '2001:db8:5:7::1')->assertStatus(202);
});

test('Turnstile is checked on our server for the newsletter form', function () {
    subscribeRequest()->assertStatus(202);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'siteverify') && $r['remoteip'] === '203.0.113.7');

    fakeOutside(turnstile: ['action' => 'contact']);
    subscribeRequest(['email' => 'b@example.com'])->assertStatus(422)->assertJsonPath('error.code', 'turnstile_failed');
    fakeOutside(turnstile: ['success' => false]);
    subscribeRequest(['email' => 'c@example.com'])->assertStatus(422);
    expect(newsletterCodesSent('b@example.com') + newsletterCodesSent('c@example.com'))->toBe(0);
});

test('only the site server may call it, in JSON', function () {
    $this->postJson('/api/v1/newsletter/subscribe', ['email' => 'a@example.com', 'placement' => 'footer'], ['X-Raqam-Visitor-Ip' => '203.0.113.7'])->assertStatus(403);
    $this->withHeaders(['X-Raqam-Site-Token' => NEWSLETTER_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7'])
        ->post('/api/v1/newsletter/subscribe', ['email' => 'a@example.com'])->assertStatus(415);
    foreach (['resend', 'confirm', 'unsubscribe'] as $path) {
        $this->flushHeaders()->postJson('/api/v1/newsletter/'.$path, [])->assertStatus(403);
    }
});

test('fields are checked strictly', function (array $bad, string $field) {
    subscribeRequest($bad)->assertStatus(422)->assertJsonStructure(['error' => ['details' => [$field]]]);
    expect(newsletterCodesSent())->toBe(0);
})->with([
    'bad e-mail' => [['email' => 'nope'], 'email'],
    'e-mail with a header' => [['email' => "reader@example.com\r\nBcc: x@example.com"], 'email'],
    'unknown form' => [['placement' => 'popup'], 'placement'],
    'no form' => [['placement' => null], 'placement'],
    'another site as page' => [['path' => '//evil.example/x'], 'path'],
    'a full address as page' => [['path' => 'https://evil.example'], 'path'],
    'name with a link' => [['name' => 'www.spam.example'], 'name'],
]);

test('an unsubscribed address that confirms again is subscribed again, with the new consent', function () {
    DB::table('subscribers')->insert(['email' => 'reader@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()->subWeek(), 'source_placement' => 'footer', 'source_path' => '/old']);

    $challenge = subscribeRequest(['placement' => 'newsletter_page', 'path' => '/newsletter'])->json('data.challenge');
    confirmCode($challenge)->assertOk();

    expect(DB::table('subscribers')->first(['status', 'unsubscribed_at', 'source_placement', 'source_path']))
        ->toEqual((object) ['status' => 'active', 'unsubscribed_at' => null, 'source_placement' => 'newsletter_page', 'source_path' => '/newsletter']);
});

test('the subscriber\'s own link unsubscribes; any other signature is refused', function () {
    $id = DB::table('subscribers')->insertGetId(['email' => 'reader@example.com', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);
    $other = DB::table('subscribers')->insertGetId(['email' => 'other@example.com', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);
    $url = NewsletterLinks::unsubscribeUrl($id, 'reader@example.com');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    expect($url)->toStartWith('https://raqam.example/newsletter/unsubscribe?s='.$id.'&t=');
    newsletterCall('unsubscribe', ['subscriber' => $other, 'signature' => $q['t']])->assertStatus(422)->assertJsonPath('error.code', 'invalid_link');
    newsletterCall('unsubscribe', ['subscriber' => $id, 'signature' => 'guess'])->assertStatus(422);
    $r = newsletterCall('unsubscribe', ['subscriber' => (string) $id, 'signature' => $q['t']])->assertOk();
    validateAgainstPath($r->json(), '/newsletter/unsubscribe', 'post', 200);
    newsletterCall('unsubscribe', ['subscriber' => $id, 'signature' => $q['t']])->assertOk();

    expect(DB::table('subscribers')->where('id', $id)->value('status'))->toBe('unsubscribed')
        ->and(DB::table('subscribers')->where('id', $id)->value('unsubscribed_at'))->not->toBeNull()
        ->and(DB::table('subscribers')->where('id', $other)->value('status'))->toBe('active');

    // Deleted and subscribed again later: the old link belongs to the old subscription and no longer works.
    DB::table('subscribers')->where('id', $id)->delete();
    $again = DB::table('subscribers')->insertGetId(['email' => 'reader@example.com', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);
    newsletterCall('unsubscribe', ['subscriber' => $again, 'signature' => $q['t']])->assertStatus(422);
});
