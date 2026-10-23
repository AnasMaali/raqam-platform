<?php

namespace App\Auth;

use App\Mail\NewDeviceSignIn;
use App\Mail\SystemMail;
use App\Models\User;
use App\Support\TrackingHash;
use App\Support\UserAgentSummary;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

/**
 * Remembers which browsers an admin has signed in from (decision 2.4, "new device alert").
 *
 * A browser is recognised by a long random cookie, given only after the code was accepted. The table
 * keeps the HMAC of the cookie, never the cookie. A device not seen for six months is forgotten and
 * counts as new when it comes back.
 */
final class KnownDevices
{
    /** __Host- makes the browser insist on Secure, Path=/ and no Domain. */
    public const COOKIE = '__Host-raqam_device';

    public const FORGET_AFTER = '6 months';

    private const LIFETIME_MINUTES = 365 * 24 * 60;

    /** HMAC of the cookie the browser sent, if any: login_attempts uses it to name the device. */
    public static function presentedHash(): ?string
    {
        $value = request()->cookie(self::COOKIE);

        return is_string($value) && $value !== '' && strlen($value) <= 128 ? TrackingHash::of($value) : null;
    }

    /** Call after a successful sign-in. Returns true when the device was new (and an alert is on its way). */
    public function recognise(User $user): bool
    {
        $hash = self::presentedHash();
        $known = $hash ? DB::table('user_devices')->where('user_id', $user->id)->where('device_hash', $hash) : null;

        if ($known && (clone $known)->where('last_seen_at', '>', DB::raw("now() - interval '".self::FORGET_AFTER."'"))->exists()) {
            // greatest(): a clock stepped back must not break the schema's last_seen_at >= first_seen_at.
            $known->update(['last_seen_at' => DB::raw('greatest(now(), first_seen_at)'), 'user_agent' => $this->userAgent()]);
            $this->queueCookie(request()->cookie(self::COOKIE));

            return false;
        }

        $known?->delete();                                   // seen too long ago: forgotten, so new again

        $value = bin2hex(random_bytes(32));
        DB::table('user_devices')->insert([
            'user_id' => $user->id,
            'device_hash' => TrackingHash::of($value),
            'user_agent' => $this->userAgent(),
        ]);
        $this->queueCookie($value);

        $summary = UserAgentSummary::of(request()->userAgent());
        $at = now();
        defer(function () use ($user, $summary, $at) {
            try {
                SystemMail::sendNow(new NewDeviceSignIn($summary['browser'], $summary['os'], $at), $user->email);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return true;
    }

    /** Deletes devices not seen for six months; returns how many. */
    public function prune(): int
    {
        return DB::table('user_devices')->where('last_seen_at', '<', DB::raw("now() - interval '".self::FORGET_AFTER."'"))->delete();
    }

    private function queueCookie(string $value): void
    {
        Cookie::queue(Cookie::make(
            name: self::COOKIE, value: $value, minutes: self::LIFETIME_MINUTES, path: '/', domain: null,
            secure: true, httpOnly: true, raw: false, sameSite: 'strict',
        ));
    }

    private function userAgent(): ?string
    {
        return ($agent = request()->userAgent()) ? mb_substr($agent, 0, 512) : null;
    }
}
