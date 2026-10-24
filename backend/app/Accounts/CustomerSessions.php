<?php

namespace App\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Signed-in customers (decision 2026-10-24): a random token handed to the site's server, which keeps it in its own
 * HttpOnly cookie and sends it back as `Authorization: Bearer`. Only its SHA-256 is a key in Redis, for 30 days at most.
 * Each session remembers a fingerprint of the password it was opened with: a password change or reset, a stopped or
 * deleted account, ends every session at once, with nothing to clean up.
 */
final class CustomerSessions
{
    public function open(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Cache::put(self::key($token), ['u' => $user->id, 'p' => self::fingerprint($user)], now()->addDays((int) config('raqam.accounts.session_days')));

        return $token;
    }

    public function user(?string $token): ?User
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            return null;
        }
        $session = Cache::get(self::key($token));
        $user = is_array($session) ? User::query()->find($session['u'] ?? null) : null;
        if ($user === null || $user->user_type !== 'customer' || ! $user->is_active || ! hash_equals(self::fingerprint($user), (string) ($session['p'] ?? ''))) {
            return null;
        }

        return $user;
    }

    public function close(?string $token): void
    {
        if (is_string($token) && $token !== '') {
            Cache::forget(self::key($token));
        }
    }

    private static function key(string $token): string
    {
        return 'account:session:'.hash('sha256', $token);
    }

    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', 'session|'.$user->id.'|'.$user->password_hash, (string) config('app.key'));
    }
}
