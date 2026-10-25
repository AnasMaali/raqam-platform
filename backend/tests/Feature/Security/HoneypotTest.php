<?php

use App\Support\Honeypot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | The hidden field of every public form (24.10). Booking and the contact form have their own tests (BookingTest,
 | ContactFormTest); here: the newsletter, the chat and the customer accounts, and a guard that no new form forgets it.
 */

const HONEYPOT_SITE_TOKEN = 'honeypot-site-token';
const HONEYPOT_PASSWORD = 'a long unleaked passphrase';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => HONEYPOT_SITE_TOKEN, 'raqam.auth.code_key' => 'code-key', 'raqam.accounts.timebox_ms' => 1, 'raqam.newsletter.timebox_ms' => 1]);
    Cache::flush();
    fakePwned();
});

function fromSite(string $path, array $body)
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => HONEYPOT_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.9', 'User-Agent' => PERSON_AGENT])
        ->postJson('/api/v1'.$path, $body);
}

/** The forms a person fills in on the site, and the routes that write but are not such a form (and why). */
const HONEYPOT_FORMS = ['/contact', '/newsletter/subscribe', '/newsletter/confirm', '/chat/messages', '/bookings', '/account/register',
    '/account/register/confirm', '/account/login', '/account/password/forgot', '/account/password/reset'];

test('every route that writes is either a form with the honeypot or named here as not a form', function () {
    $notForms = [
        // counters the site reports on a visitor's behalf; nothing typed
        '/ads/impressions', '/services/{slug}/contact', '/stats/hits',
        // a link from an e-mail, a button on a page already behind a form, or a private token from a link
        '/newsletter/resend', '/newsletter/unsubscribe', '/account/register/resend', '/bookings/track', '/bookings/pay', '/payments/return',
        // calls of another service, proved by a secret of their own
        '/newsletter/brevo-webhook', '/payments/webhooks/{gateway}',
        // a signed-in customer's own actions (behind the session token)
        '/account/logout', '/account/profile', '/account/password', '/account', '/account/saved/{post}', '/account/follows/{kind}/{id}',
    ];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1') || array_diff($route->methods(), ['GET', 'HEAD']) === []) {
            continue;
        }
        $path = substr('/'.$route->uri(), 7);
        expect(in_array($path, HONEYPOT_FORMS, true) || in_array($path, $notForms, true))->toBeTrue("{$path} writes: give it the honeypot or say why it is not a form");
    }
});

test('the newsletter: a caught robot gets a challenge of the usual shape, no code is sent, and the challenge leads nowhere', function () {
    $body = ['email' => 'reader@example.com', 'name' => 'قارئ', 'placement' => 'footer', 'turnstile_token' => 'tok'];

    $challenge = fromSite('/newsletter/subscribe', [...$body, Honeypot::FIELD => 'https://spam.example'])->assertStatus(202)
        ->assertJsonStructure(['data' => ['challenge', 'resend_after', 'expires_in']])->json('data.challenge');

    expect(sentEmails())->toBe([])->and(DB::table('subscribers')->count())->toBe(0)->and(strlen($challenge))->toBeGreaterThan(100);
    fromSite('/newsletter/confirm', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);

    // A person with the field empty is not touched by it.
    fromSite('/newsletter/subscribe', [...$body, Honeypot::FIELD => ''])->assertStatus(202);
    expect(newsletterCodesSent())->toBe(1);
});

test('the newsletter code form: a filled field is a wrong code and burns none of the real tries', function () {
    $challenge = fromSite('/newsletter/subscribe', ['email' => 'reader@example.com', 'placement' => 'footer', 'turnstile_token' => 'tok'])->json('data.challenge');
    $code = newsletterCode();

    foreach (range(1, 6) as $i) {
        fromSite('/newsletter/confirm', ['challenge' => $challenge, 'code' => $code, Honeypot::FIELD => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    }
    expect(DB::table('subscribers')->count())->toBe(0);

    fromSite('/newsletter/confirm', ['challenge' => $challenge, 'code' => $code])->assertOk();
    expect(DB::table('subscribers')->count())->toBe(1);
});

test('the chat: a caught robot gets the shape of a reply and no conversation is opened or kept', function () {
    $this->artisan('bot:install')->assertSuccessful();

    $reply = fromSite('/chat/messages', ['message' => 'كم سعر الدولار؟', Honeypot::FIELD => 'x'])->assertOk()->json('data');

    expect($reply['conversation'])->toMatch('/^[0-9a-f-]{36}\.[0-9a-f]{64}$/')->and($reply['reply']['kind'])->toBe('no_answer')
        ->and(DB::table('bot_conversations')->count())->toBe(0)->and(DB::table('bot_messages')->count())->toBe(0);
    fromSite('/chat/messages', ['message' => 'كم سعر الدولار؟'])->assertOk();
    expect(DB::table('bot_conversations')->count())->toBe(1);
});

test('the account forms: a caught robot is answered like a person and nothing is checked, stored or sent', function () {
    DB::table('feature_flags')->where('key', 'customer_accounts')->update(['is_enabled' => true]);
    $robot = [Honeypot::FIELD => 'x'];

    $challenge = fromSite('/account/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => HONEYPOT_PASSWORD, ...$robot])
        ->assertStatus(202)->json('data.challenge');
    expect(sentEmails())->toBe([])->and(DB::table('users')->where('email', 'sami@example.com')->exists())->toBeFalse();
    fromSite('/account/register/confirm', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422);

    // A real account, then the other forms with the field filled.
    $real = fromSite('/account/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => HONEYPOT_PASSWORD])->json('data.challenge');
    $sent = sentEmails();
    $code = preg_match('/\b(\d{6})\b/', strip_tags(end($sent)->getHtmlBody()), $m) ? $m[1] : null;
    fromSite('/account/register/confirm', ['challenge' => $real, 'code' => $code, ...$robot])->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    fromSite('/account/register/confirm', ['challenge' => $real, 'code' => $code])->assertStatus(201);
    $mails = count(sentEmails());

    fromSite('/account/login', ['email' => 'sami@example.com', 'password' => HONEYPOT_PASSWORD, ...$robot])->assertStatus(422)->assertJsonPath('error.code', 'bad_credentials');
    expect(DB::table('login_attempts')->where('email', 'sami@example.com')->count())->toBe(0);   // nothing was even checked
    fromSite('/account/password/forgot', ['email' => 'sami@example.com', ...$robot])->assertStatus(202)->assertJsonStructure(['data' => ['challenge']]);
    fromSite('/account/password/reset', ['challenge' => $real, 'code' => '123456', 'password' => 'another long passphrase', ...$robot])->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    expect(count(sentEmails()))->toBe($mails);

    fromSite('/account/login', ['email' => 'sami@example.com', 'password' => HONEYPOT_PASSWORD])->assertOk();
});
