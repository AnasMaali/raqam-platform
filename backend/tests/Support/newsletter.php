<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

/** Helpers for the newsletter tests: the site's calls, the code from the mail, Brevo and Turnstile stand-ins. */
const NEWSLETTER_SITE_TOKEN = 'newsletter-site-token';

function newsletterCall(string $path, array $body, string $ip = '203.0.113.7')
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => NEWSLETTER_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => $ip, 'User-Agent' => PERSON_AGENT])
        ->postJson('/api/v1/newsletter/'.$path, $body);
}

function subscribeRequest(array $overrides = [], string $ip = '203.0.113.7')
{
    return newsletterCall('subscribe', array_merge(['email' => 'reader@example.com', 'name' => 'قارئ', 'placement' => 'post_end', 'path' => '/news/7/خبر', 'turnstile_token' => 'tok'], $overrides), $ip);
}

/** The code of the newest code mail to this address, or null. */
function newsletterCode(string $email = 'reader@example.com'): ?string
{
    $mails = array_values(array_filter(sentEmailsTo($email), fn (Email $m) => str_contains((string) $m->getSubject(), 'رمز تأكيد اشتراكك')));
    if ($mails === []) {
        return null;
    }
    preg_match('/letter-spacing:8px;font-weight:bold">(\d{6})</', (string) end($mails)->getHtmlBody(), $m);

    return $m[1] ?? null;
}

function newsletterCodesSent(string $email = 'reader@example.com'): int
{
    return count(array_filter(sentEmailsTo($email), fn (Email $m) => str_contains((string) $m->getSubject(), 'رمز تأكيد اشتراكك')));
}

/**
 * Brevo and Turnstile stand-ins. $contact: what Brevo says about a contact (null = it has none).
 *
 * @param  array<string, mixed>|null  $contact
 */
function fakeOutside(?array $contact = ['emailBlacklisted' => false, 'listIds' => [3]], int $brevoStatus = 200, array $turnstile = []): void
{
    Http::swap(new Factory);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(array_merge(['success' => true, 'action' => 'newsletter', 'hostname' => 'raqam.ps'], $turnstile)),
        'brevo.test/v3/contacts/*' => fn ($request) => $request->method() === 'GET'
            ? ($contact === null ? Http::response(['code' => 'document_not_found'], 404) : Http::response($contact + ['email' => 'x'], $brevoStatus))
            : Http::response(null, $brevoStatus === 200 ? 204 : $brevoStatus),
        'brevo.test/v3/contacts' => Http::response(['id' => 42], $brevoStatus === 200 ? 201 : $brevoStatus),
        'brevo.test/v3/emailCampaigns/*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => 77, 'status' => 'sent', 'sentDate' => '2026-10-24T10:00:00Z', 'statistics' => ['globalStats' => ['delivered' => 120, 'uniqueViews' => 60, 'uniqueClicks' => 15, 'unsubscriptions' => 2]]], $brevoStatus)
            : Http::response(null, $brevoStatus === 200 ? 204 : $brevoStatus),
        'brevo.test/v3/emailCampaigns' => Http::response(['id' => 77], $brevoStatus === 200 ? 201 : $brevoStatus),
        '*' => Http::response('unexpected', 500),
    ]);
}

function brevoOn(): void
{
    config(['raqam.newsletter.brevo.key' => 'xkeysib-test-KEY-123456', 'raqam.newsletter.brevo.list_id' => '3',
        'raqam.newsletter.brevo.api_base' => 'https://brevo.test/v3', 'raqam.newsletter.brevo.webhook_token' => str_repeat('w', 40)]);
}
