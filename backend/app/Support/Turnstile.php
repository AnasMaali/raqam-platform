<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cloudflare Turnstile, checked by our server (24.10): the browser's token is sent to siteverify with our secret and the
 * visitor's address, and passes only when Cloudflare says so for this form (the action) on one of our hosts. Any failure
 * to ask Cloudflare refuses the form (fails closed). Without a secret the check is skipped; production refuses to start
 * without one (ProductionChecks), so that is local development only.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function enabled(): bool
    {
        return filled(config('raqam.turnstile.secret'));
    }

    public static function passes(?string $token, ?string $ip, string $action): bool
    {
        if (! self::enabled()) {
            return true;
        }
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $answer = Http::asForm()->timeout(5)->withoutRedirecting()->post(config('raqam.turnstile.verify_url') ?: self::VERIFY_URL, array_filter([
                'secret' => (string) config('raqam.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
                'idempotency_key' => (string) Str::uuid(),
            ]));
        } catch (\Throwable) {
            return false;
        }
        if (! $answer->successful() || $answer->json('success') !== true) {
            return false;
        }
        if ($answer->json('action') !== $action) {
            return false;   // a token solved for another form
        }
        $hosts = array_filter(array_map('trim', explode(',', (string) config('raqam.turnstile.hostnames'))));

        return $hosts === [] || in_array($answer->json('hostname'), $hosts, true);
    }
}
