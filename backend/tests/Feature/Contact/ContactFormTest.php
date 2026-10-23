<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

const SITE_TOKEN = 'contact-site-token';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => SITE_TOKEN, 'raqam.turnstile.secret' => 'turnstile-secret', 'raqam.turnstile.hostnames' => 'raqam.ps,www.raqam.ps']);
    Cache::flush();
    $this->admin = staffAdmin('admin@raqam.example');
    turnstileAnswers();
});

/** Cloudflare's siteverify stand-in: success for this action and host unless told otherwise. */
function turnstileAnswers(array $answer = [], int $status = 200): void
{
    Http::swap(new Factory);   // stubs stack: a new answer replaces the old one
    Http::fake(['challenges.cloudflare.com/*' => Http::response(array_merge(['success' => true, 'action' => 'contact', 'hostname' => 'raqam.ps'], $answer), $status)]);
}

function contactForm(array $overrides = []): array
{
    return array_merge(['name' => 'سامي الخطيب', 'email' => 'sami@example.com', 'phone' => '', 'subject' => 'استفسار عن الإعلانات', 'message' => "مرحباً،\nأريد أن أعلن.", 'turnstile_token' => 'tok', 'website' => ''], $overrides);
}

function sendContact(array $overrides = [], string $ip = '203.0.113.7', ?array $headers = null)
{
    return test()->flushHeaders()->withHeaders($headers ?? ['X-Raqam-Site-Token' => SITE_TOKEN, 'X-Raqam-Visitor-Ip' => $ip, 'User-Agent' => PERSON_AGENT])
        ->postJson('/api/v1/contact', contactForm($overrides));
}

test('a message is received, kept for the panel without the raw address, and the admins are told', function () {
    sendContact()->assertStatus(202)->assertExactJson(['data' => ['received' => true]])->assertHeader('Cache-Control', 'no-store, private');

    $row = DB::table('contact_messages')->first();
    expect($row->name)->toBe('سامي الخطيب')->and($row->message)->toBe("مرحباً،\nأريد أن أعلن.")->and($row->status)->toBe('new')
        ->and($row->ip_hash)->toMatch('/^[0-9a-f]{64}$/')->and(json_encode(DB::table('contact_messages')->get()))->not->toContain('203.0.113.7');

    expect(DB::table('admin_notifications')->where('user_id', $this->admin)->first(['category', 'title', 'action_path']))
        ->toEqual((object) ['category' => 'messages', 'title' => 'رسالة جديدة من سامي الخطيب', 'action_path' => "contact-messages/{$row->id}"]);
    $alert = sentEmailsTo('admin@raqam.example')[0];
    expect($alert->getSubject())->toBe('رسالة جديدة: استفسار عن الإعلانات')
        ->and($alert->getReplyTo()[0]->getAddress())->toBe('sami@example.com')
        ->and($alert->getHtmlBody())->toContain('مرحباً،<br>')->toContain('أريد أن أعلن.');
});

test('the sender gets the automatic reply, which says nothing they wrote but the name', function () {
    sendContact(['subject' => 'Visit https://spam.example now', 'message' => 'buy https://spam.example'])->assertStatus(202);

    $reply = sentEmailsTo('sami@example.com');
    expect($reply)->toHaveCount(1)->and($reply[0]->getSubject())->toBe('وصلتنا رسالتك')
        ->and($reply[0]->getHtmlBody())->toContain('سامي الخطيب')->not->toContain('spam.example');
});

test('one address gets one automatic reply a day, whoever asks for it (decision 2026-10-23)', function () {
    sendContact(ip: '203.0.113.7')->assertStatus(202);
    sendContact(['email' => 'SAMI@example.com'], ip: '198.51.100.9')->assertStatus(202);   // another visitor, same mailbox
    sendContact(ip: '2001:db8::1')->assertStatus(202);

    // Every message is kept and reaches the admins; the mailbox got one reply.
    expect(DB::table('contact_messages')->count())->toBe(3)
        ->and(count(sentEmailsTo('sami@example.com')))->toBe(1)
        ->and(Redis::connection('cache')->keys('*sami*'))->toBe([]);

    $this->travel(25)->hours();
    sendContact(ip: '192.0.2.44')->assertStatus(202);
    expect(count(sentEmailsTo('sami@example.com')))->toBe(2);
});

test('only the site server may send it: without its token or a visitor address it is refused', function (?array $headers) {
    sendContact(headers: $headers)->assertStatus(403)->assertJsonPath('error.code', 'site_only');
    expect(DB::table('contact_messages')->count())->toBe(0);
})->with([
    'no token' => [['X-Raqam-Visitor-Ip' => '203.0.113.7']],
    'wrong token' => [['X-Raqam-Site-Token' => 'guess', 'X-Raqam-Visitor-Ip' => '203.0.113.7']],
    'no visitor address' => [['X-Raqam-Site-Token' => SITE_TOKEN]],
    'not an address' => [['X-Raqam-Site-Token' => SITE_TOKEN, 'X-Raqam-Visitor-Ip' => 'localhost']],
]);

test('it takes JSON only', function () {
    $this->withHeaders(['X-Raqam-Site-Token' => SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7'])
        ->post('/api/v1/contact', contactForm())->assertStatus(415);
});

test('a robot that fills the hidden field gets the same answer, and nothing is kept or sent', function () {
    sendContact(['website' => 'https://spam.example'])->assertStatus(202)->assertExactJson(['data' => ['received' => true]]);

    expect(DB::table('contact_messages')->count())->toBe(0)->and(sentEmails())->toBe([])->and(DB::table('admin_notifications')->count())->toBe(0);
});

test('Turnstile is checked on our server with the secret and the visitor address', function () {
    sendContact(ip: '203.0.113.7')->assertStatus(202);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $r['secret'] === 'turnstile-secret' && $r['response'] === 'tok' && $r['remoteip'] === '203.0.113.7' && filled($r['idempotency_key']));
});

test('Turnstile saying no, for another form or another site, or not answering refuses the message', function (array $answer, int $status) {
    turnstileAnswers($answer, $status);

    sendContact()->assertStatus(422)->assertJsonPath('error.code', 'turnstile_failed');
    expect(DB::table('contact_messages')->count())->toBe(0)->and(sentEmails())->toBe([]);
})->with([
    'not solved' => [['success' => false, 'error-codes' => ['invalid-input-response']], 200],
    'another form' => [['action' => 'newsletter'], 200],
    'another site' => [['hostname' => 'evil.example'], 200],
    'Cloudflare down' => [[], 503],
]);

test('Cloudflare out of reach refuses the message (fails closed)', function () {
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    sendContact()->assertStatus(422)->assertJsonPath('error.code', 'turnstile_failed');
    expect(DB::table('contact_messages')->count())->toBe(0);
});

test('without a Turnstile secret (local development) the check is skipped', function () {
    config(['raqam.turnstile.secret' => null]);
    sendContact(['turnstile_token' => ''])->assertStatus(202);
    Http::assertNothingSent();
});

test('Turnstile without a token is refused, and a token that is too long never reaches Cloudflare', function () {
    sendContact(['turnstile_token' => ''])->assertStatus(422)->assertJsonPath('error.code', 'turnstile_failed');
    sendContact(['turnstile_token' => str_repeat('a', 2049)])->assertStatus(422);
    Http::assertNothingSent();
});

test('fields are checked strictly, each with an Arabic reason', function (array $bad, string $field) {
    sendContact($bad)->assertStatus(422)->assertJsonPath('error.code', 'invalid')->assertJsonStructure(['error' => ['details' => [$field]]]);
    expect(DB::table('contact_messages')->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name with a link' => [['name' => 'سامي https://spam.example'], 'name'],
    'name with a domain' => [['name' => 'سامي spam.com'], 'name'],
    'name with an address' => [['name' => 'سامي a@b.co'], 'name'],
    'name on two lines' => [['name' => "سامي\nBcc: x@example.com"], 'name'],
    'bad e-mail' => [['email' => 'not-an-email'], 'email'],
    'e-mail with a header' => [['email' => "sami@example.com\r\nBcc: victim@example.com"], 'email'],
    'two e-mails' => [['email' => 'a@example.com, b@example.com'], 'email'],
    'local phone' => [['phone' => '0599123456'], 'phone'],
    'phone letters' => [['phone' => '+97059abc'], 'phone'],
    'subject on two lines' => [['subject' => "مرحبا\r\nBcc: x@example.com"], 'subject'],
    'message too long' => [['message' => str_repeat('ا', 5001)], 'message'],
    'message too short' => [['message' => 'ا'], 'message'],
    'not text' => [['message' => ['x']], 'message'],
]);

test('a phone typed with Arabic digits, spaces or 00 is kept in international form', function (string $typed) {
    sendContact(['phone' => $typed])->assertStatus(202);
    expect(DB::table('contact_messages')->value('phone'))->toBe('+970599123456');
})->with(['+970599123456', '00970 599 123 456', '+٩٧٠٥٩٩١٢٣٤٥٦', '(+970) 599-123-456']);

test('five messages an hour and twenty a day per visitor; an IPv6 visitor is its /64', function () {
    foreach (range(1, 5) as $i) {
        sendContact(['email' => "p{$i}@example.com"], ip: '2001:db8:1:2::'.$i)->assertStatus(202);
    }
    sendContact(['email' => 'p6@example.com'], ip: '2001:db8:1:2::ffff')->assertStatus(429)->assertJsonPath('error.code', 'too_many');
    sendContact(['email' => 'p7@example.com'], ip: '2001:db8:1:3::1')->assertStatus(202);   // another /64

    $this->travel(61)->minutes();
    sendContact(['email' => 'p8@example.com'], ip: '2001:db8:1:2::1')->assertStatus(202);
    expect(DB::table('contact_messages')->count())->toBe(7);
});

test('a flood of requests from one visitor is cut at 10 a minute', function () {
    foreach (range(1, 10) as $i) {
        sendContact(['name' => ''])->assertStatus(422);
    }
    sendContact()->assertStatus(429);
});

test('beyond the hourly cap the admins still get the bell, but no more e-mails', function () {
    config(['raqam.contact.alert_emails_per_hour' => 2]);
    foreach (range(1, 3) as $i) {
        sendContact(['email' => "p{$i}@example.com"], ip: "198.51.100.{$i}")->assertStatus(202);
    }

    expect(DB::table('admin_notifications')->where('user_id', $this->admin)->count())->toBe(3)
        ->and(sentEmailsTo('admin@raqam.example'))->toHaveCount(2);
});

test('the contact form is in the API contract', function () {
    $body = sendContact()->assertStatus(202)->json();
    validateAgainstPath($body, '/contact', 'post', 202);
});
