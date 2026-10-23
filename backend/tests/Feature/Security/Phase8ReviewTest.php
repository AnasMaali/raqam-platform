<?php

use App\Support\ProductionChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Findings of the phase 8 security review (private/phase8-security-report.md). Each test failed before its fix.

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => NEWSLETTER_SITE_TOKEN, 'raqam.auth.code_key' => 'code-key',
        'raqam.turnstile.secret' => 'turnstile-secret', 'raqam.turnstile.hostnames' => 'raqam.ps', 'raqam.newsletter.timebox_ms' => 1]);
    Cache::flush();
    fakeOutside();
});

test('F1: production needs the site\'s hostnames for Turnstile, or a token solved on any site would pass', function () {
    productionConfig(['raqam.turnstile.hostnames' => '']);

    expect(implode(' ', ProductionChecks::failures()))->toContain('TURNSTILE_HOSTNAMES');
});

test('F2: bidirectional override characters cannot disguise a name or subject in the inbox or in e-mails', function () {
    $rlo = "\u{202E}";
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['success' => true, 'action' => 'contact', 'hostname' => 'raqam.ps'])]);
    staffAdmin('admin@raqam.example');

    test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => NEWSLETTER_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7'])->postJson('/api/v1/contact', [
        'name' => "سامي{$rlo}ردان", 'email' => 'sami@example.com', 'subject' => "عرض{$rlo}فاتورة", 'message' => "نص{$rlo}\u{2066}مخفي\u{2069}", 'turnstile_token' => 't',
    ])->assertStatus(202);

    $row = DB::table('contact_messages')->first();
    $all = $row->name.$row->subject.$row->message;
    foreach (sentEmails() as $mail) {
        $all .= $mail->getSubject().$mail->getHtmlBody().$mail->getTextBody();
    }
    expect($all)->not->toContain($rlo)->not->toContain("\u{2066}")->not->toContain("\u{2069}")
        ->and($row->name)->toBe('سامي ردان');

    $chat = test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => NEWSLETTER_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.8'])
        ->postJson('/api/v1/chat/messages', ['message' => "سؤال{$rlo}مقلوب"])->assertOk();
    expect(DB::table('bot_messages')->where('role', 'user')->value('content'))->not->toContain($rlo);
});

test('F3: somebody else\'s challenge for the same address cannot use up the subscriber\'s tries', function () {
    $mine = subscribeRequest()->json('data.challenge');
    $code = newsletterCode();
    // Within the minute another request for the address gets its own challenge (no new code is sent).
    $theirs = subscribeRequest([], '198.51.100.66')->json('data.challenge');
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 6) as $i) {
        newsletterCall('confirm', ['challenge' => $theirs, 'code' => $wrong], '198.51.100.66')->assertStatus(422);
    }

    newsletterCall('confirm', ['challenge' => $mine, 'code' => $code])->assertOk();
    expect(DB::table('subscribers')->value('status'))->toBe('active');
});
