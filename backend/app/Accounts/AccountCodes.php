<?php

namespace App\Accounts;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * The 6-digit e-mail codes of customer accounts (17.3, 17.5), the same pattern as the newsletter's: the API has no
 * session, so every request gets an encrypted "challenge" (the address, the purpose, what to do on success, a random
 * value) and the code is an HMAC of the address, that value and the digits under the code key. A code is good only with
 * its own challenge, once, before it expires, within 5 tries counted per code and per challenge. One address gets a code
 * a minute at most, 3 an hour and 6 a day, whoever asks; beyond that the answer is the same and nothing is sent.
 */
final class AccountCodes
{
    public const PURPOSES = [
        'account_verify' => ['template' => 'account_verify_code', 'minutes' => 'verify_minutes'],
        'password_reset' => ['template' => 'password_reset_code', 'minutes' => 'reset_minutes'],
    ];

    /**
     * @param  array<string, mixed>  $data  what the challenge carries for the step after the code
     * @param  bool  $send  false: make the challenge and send nothing (an address with no account looks the same)
     */
    public function challenge(string $purpose, string $email, array $data, bool $send): string
    {
        $challenge = ['u' => $purpose, 'e' => mb_strtolower($email), 'd' => $data, 't' => now()->getTimestamp(), 'r' => bin2hex(random_bytes(16))];
        if ($send) {
            $this->issue($challenge);
        }

        return Crypt::encryptString(json_encode($challenge, JSON_UNESCAPED_UNICODE));
    }

    /** A new code for the same challenge (a minute after the last one at the earliest). @return array<string, mixed> */
    public function resend(string $token, string $purpose, bool $send): array
    {
        $challenge = $this->open($token, $purpose);
        if ($send) {
            $this->issue($challenge);
        }

        return $challenge;
    }

    /**
     * Checks the code and uses it up. Run inside the caller's transaction, so the code is spent only with the work it opens.
     *
     * @return array<string, mixed> the challenge
     *
     * @throws AccountRefused
     */
    public function consume(string $token, string $purpose, string $code): array
    {
        $challenge = $this->open($token, $purpose);
        if (preg_match('/^\d{6}\z/', $code) !== 1) {
            throw new AccountRefused('bad_code', 'الرمز غير صحيح.');
        }
        $open = DB::table('verification_codes')->where('purpose', $purpose)->where('email', $challenge['e'])->whereNull('consumed_at');
        $row = (clone $open)->where('expires_at', '>', DB::raw('now()'))->orderByDesc('id')->lockForUpdate()->first();
        if ($row === null) {
            throw (clone $open)->exists() ? new AccountRefused('expired', 'انتهت صلاحية الرمز؛ اطلب رمزاً جديداً.') : new AccountRefused('bad_code', 'الرمز غير صحيح.');
        }
        $tries = "account:tries:{$row->id}:".hash_hmac('sha256', (string) $challenge['r'], (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($tries, (int) config('raqam.accounts.code_attempts'))) {
            throw new AccountRefused('bad_code', 'الرمز غير صحيح.');
        }
        if (! hash_equals($row->code_hash, self::hash($purpose, $challenge['e'], $challenge['r'], $code))) {
            RateLimiter::hit($tries, 3600);
            throw new AccountRefused('bad_code', 'الرمز غير صحيح.');
        }
        DB::table('verification_codes')->where('id', $row->id)->update(['consumed_at' => DB::raw('greatest(now(), created_at)')]);

        return $challenge;
    }

    public static function hash(string $purpose, string $email, string $nonce, #[\SensitiveParameter] string $code): string
    {
        $key = config('raqam.auth.code_key') ?: throw new RuntimeException('ADMIN_CODE_KEY is not set; codes cannot be made safely.');

        return hash_hmac('sha256', 'account|'.$purpose.'|'.mb_strtolower($email).'|'.$nonce.'|'.$code, (string) $key);
    }

    /** @param  array<string, mixed>  $challenge */
    private function issue(array $challenge): void
    {
        $email = (string) $challenge['e'];
        $who = $challenge['u'].':'.hash_hmac('sha256', $email, (string) config('app.key'));
        $limits = [["account:cooldown:{$who}", 1, (int) config('raqam.accounts.resend_seconds')], ["account:codes-hour:{$who}", (int) config('raqam.accounts.codes_per_email_hour'), 3600],
            ["account:codes-day:{$who}", (int) config('raqam.accounts.codes_per_email_day'), 86400]];
        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return;
            }
        }
        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $purpose = self::PURPOSES[$challenge['u']];
        $minutes = (int) config('raqam.accounts.'.$purpose['minutes']);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($challenge, $email, $code, $minutes, $purpose) {
            DB::table('verification_codes')->where('purpose', $challenge['u'])->where('email', $email)->whereNull('consumed_at')
                ->update(['consumed_at' => DB::raw('greatest(now(), created_at)')]);   // a new code replaces the old ones
            DB::table('verification_codes')->insert(['purpose' => $challenge['u'], 'email' => $email,
                'code_hash' => self::hash($challenge['u'], $email, (string) $challenge['r'], $code), 'expires_at' => DB::raw("now() + make_interval(mins => {$minutes})")]);
            SystemMail::queue(new TemplatedEmail($purpose['template'], ['الرمز' => $code, 'الدقائق' => $minutes]), $email);
        });
    }

    /** @return array<string, mixed> @throws AccountRefused */
    private function open(string $token, string $purpose): array
    {
        try {
            $c = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $c = null;
        }
        $minutes = (int) config('raqam.accounts.'.self::PURPOSES[$purpose]['minutes']);
        $fresh = is_array($c) && ($c['u'] ?? null) === $purpose && is_int($c['t'] ?? null) && now()->getTimestamp() - $c['t'] <= ($minutes + 5) * 60;
        if (! $fresh || ! is_string($c['e'] ?? null) || ! is_string($c['r'] ?? null) || ! is_array($c['d'] ?? null)) {
            throw new AccountRefused('expired', 'انتهت صلاحية الطلب؛ ابدأ من جديد.');
        }

        return $c;
    }
}
