<?php

namespace App\Auth;

use App\Mail\AdminLoginCode;
use App\Models\User;
use App\Support\TrackingHash;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use RuntimeException;

/**
 * Admin sign-in in two steps: e-mail + password, then a 6-digit code sent by e-mail.
 *
 * Nothing here reveals whether the e-mail or the password was right: the second step is shown
 * after the first in every case, and only a valid, active staff account gets a code. The code is
 * HMAC-ed with a secret key together with the e-mail and a random value kept in the session that
 * entered the password, so a code is useless in any other session.
 */
final class AdminSignIn
{
    private const STATE = 'admin_sign_in';

    /** Fixed work for a password check against an account that does not exist. */
    private static ?string $decoyHash = null;

    public function __construct(private readonly LoginAttempts $attempts) {}

    /**
     * Step 1. Returns null when the visitor moves on to the code page (whatever the outcome), or the
     * number of seconds to wait when too many attempts were made.
     */
    public function begin(string $email, #[\SensitiveParameter] string $password): ?int
    {
        $email = Str::lower(trim($email));

        if (($wait = $this->throttle($email)) !== null) {
            return $wait;
        }

        $userId = app(Timebox::class)->call(function () use ($email, $password): ?int {
            $user = User::query()->where('email', $email)->first();
            $passwordMatches = Hash::check($password, $user?->password_hash ?? self::decoyHash());
            $allowed = $user && $passwordMatches && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());

            // Recorded inside the box, so a refused sign-in takes as long as an accepted one.
            if (! $allowed) {
                $this->attempts->record($email, $user?->id, $user && $passwordMatches ? LoginAttempts::INACTIVE : LoginAttempts::BAD_CREDENTIALS);
            }

            return $allowed ? $user->id : null;
        }, (int) config('auth.timebox_duration', 200_000));

        session()->regenerate();
        $binding = bin2hex(random_bytes(32));
        session()->put(self::STATE, [
            'email' => $email,
            'binding' => $binding,
            'user_id' => $userId,
            'started_at' => now()->getTimestamp(),
            'failures' => 0,
        ]);

        $this->sendCode($email, $binding, $userId);

        return null;
    }

    public function hasPending(): bool
    {
        $state = session(self::STATE);

        return $state !== null && now()->getTimestamp() - $state['started_at'] <= $this->codeSeconds();
    }

    /** Signs the user in when the code is right; null for every kind of failure. */
    public function verify(#[\SensitiveParameter] string $code): ?User
    {
        $state = session(self::STATE);
        if (! $state) {
            return null;
        }

        if (! $this->hasPending()) {
            $this->attempts->record($state['email'], $state['user_id'], LoginAttempts::EXPIRED_CODE);
            $this->forget();

            return null;
        }

        $code = preg_replace('/\s+/', '', $code);

        // Padded to a fixed duration so the answer time does not tell a real account from an unknown one.
        [$user, $failure] = app(Timebox::class)->call(function () use ($state, $code): array {
            [$user, $failure] = preg_match('/^\d{6}$/', $code) && $state['user_id'] && $state['failures'] < $this->maxAttempts()
                ? DB::transaction(fn () => $this->consume($state, $code))
                : [null, LoginAttempts::BAD_CODE];

            $this->attempts->record($state['email'], $state['user_id'], $failure);

            return [$user, $failure];
        }, (int) config('auth.timebox_duration', 200_000));

        if (! $user) {
            $state['failures']++;
            $state['failures'] >= $this->maxAttempts() ? $this->forget() : session()->put(self::STATE, $state);

            return null;
        }

        Filament::auth()->login($user);
        session()->regenerate();
        $this->forget();
        app(AdminSessionLimits::class)->start();
        AdminAudit::login($user);
        app(KnownDevices::class)->recognise($user);          // the device cookie exists only after the code

        return $user;
    }

    /** @return 'sent'|'too_soon'|'limit' */
    public function resend(): string
    {
        $state = session(self::STATE);
        if (! $state) {
            return 'limit';
        }

        return $this->sendCode($state['email'], $state['binding'], $state['user_id']);
    }

    /** Seconds until the next code may be requested; 0 when it may be requested now. */
    public function secondsUntilResend(): int
    {
        $state = session(self::STATE);

        return $state ? RateLimiter::availableIn($this->limitKey('cooldown', $state['email'])) : 0;
    }

    public function forget(): void
    {
        session()->forget(self::STATE);
    }

    /**
     * Counts every request the same way for every e-mail (a real account or not), so the limits
     * cannot be used to tell them apart; only a valid account gets a code, after the response.
     */
    private function sendCode(string $email, string $binding, ?int $userId): string
    {
        $cooldown = $this->limitKey('cooldown', $email);
        $hourly = $this->limitKey('hourly', $email);

        if (RateLimiter::tooManyAttempts($cooldown, 1)) {
            return 'too_soon';
        }
        if (RateLimiter::tooManyAttempts($hourly, (int) config('raqam.auth.sends_per_hour'))) {
            return 'limit';
        }

        RateLimiter::hit($cooldown, (int) config('raqam.auth.resend_seconds'));
        RateLimiter::hit($hourly, 3600);

        if ($userId) {
            defer(fn () => $this->issueCode($userId, $email, $binding));
        }

        return 'sent';
    }

    private function issueCode(int $userId, string $email, string $binding): void
    {
        try {
            $user = User::find($userId);
            if (! $user || ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                return;
            }

            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

            DB::transaction(function () use ($email, $binding, $code) {
                DB::table('verification_codes')->where('purpose', 'admin_login')->where('email', $email)
                    ->whereNull('consumed_at')->update(['consumed_at' => DB::raw('now()')]);   // a new code replaces the old ones
                DB::table('verification_codes')->insert([
                    'purpose' => 'admin_login', 'email' => $email, 'code_hash' => $this->hash($email, $binding, $code),
                    'expires_at' => now()->addSeconds($this->codeSeconds()),
                ]);
            });

            Mail::to($user->email)->send(new AdminLoginCode($code, (int) config('raqam.auth.code_minutes')));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array{email: string, binding: string, user_id: int}  $state
     * @return array{0: ?User, 1: ?string} the user, or the reason for refusing
     */
    private function consume(array $state, string $code): array
    {
        $open = DB::table('verification_codes')
            ->where('purpose', 'admin_login')->where('email', $state['email'])->whereNull('consumed_at');

        $row = (clone $open)->where('expires_at', '>', now())->orderByDesc('id')->lockForUpdate()->first();

        if (! $row) {
            return [null, (clone $open)->where('expires_at', '<=', now())->exists() ? LoginAttempts::EXPIRED_CODE : LoginAttempts::BAD_CODE];
        }

        if ($row->attempts >= $this->maxAttempts()) {
            return [null, LoginAttempts::BAD_CODE];
        }

        if (! hash_equals($row->code_hash, $this->hash($state['email'], $state['binding'], $code))) {
            DB::table('verification_codes')->where('id', $row->id)->increment('attempts');

            return [null, LoginAttempts::BAD_CODE];
        }

        DB::table('verification_codes')->where('id', $row->id)->update(['consumed_at' => DB::raw('now()')]);
        $user = User::find($state['user_id']);

        return $user && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            ? [$user, null]
            : [null, LoginAttempts::INACTIVE];
    }

    /**
     * Every password submission counts (right or wrong) against three limits: e-mail + IP per minute,
     * the e-mail alone and the IP alone per hour. Keys hold hashes, never the raw e-mail or IP.
     * Returns the seconds to wait when one is used up.
     */
    private function throttle(string $email): ?int
    {
        $ip = TrackingHash::ip() ?? 'none';
        $pair = 'admin-signin:pair:'.hash('sha256', $email.'|'.$ip);
        $limits = [
            [$pair, (int) config('raqam.auth.attempts_per_minute'), 60],
            ['admin-signin:email:'.hash('sha256', $email), (int) config('raqam.auth.attempts_per_email_hour'), 3600],
            ['admin-signin:ip:'.$ip, (int) config('raqam.auth.attempts_per_ip_hour'), 3600],
        ];

        $wait = 0;
        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            // One row per minute is enough evidence; a flood of refused requests must not flood the table.
            if (RateLimiter::attempt('admin-signin:logged:'.$pair, 1, fn () => true, 60)) {
                $this->attempts->record($email, null, LoginAttempts::THROTTLED);
            }

            return $wait;
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }

    private function hash(string $email, string $binding, #[\SensitiveParameter] string $code): string
    {
        $key = config('raqam.auth.code_key')
            ?: throw new RuntimeException('ADMIN_CODE_KEY is not set; admin sign-in codes cannot be issued.');

        return hash_hmac('sha256', "admin_login|{$email}|{$binding}|{$code}", $key);
    }

    private function limitKey(string $name, string $email): string
    {
        return self::codeLimitKey($name, $email);
    }

    private static function codeLimitKey(string $name, string $email): string
    {
        return "admin-code:{$name}:".hash('sha256', $email);
    }

    /**
     * Every limit that belongs to an e-mail alone, for admin:unlock. The e-mail + IP key is not here:
     * it needs the IP, and it expires on its own within a minute.
     *
     * @return list<string>
     */
    public static function emailLimitKeys(string $email): array
    {
        $email = Str::lower(trim($email));

        return [
            'admin-signin:email:'.hash('sha256', $email),
            self::codeLimitKey('cooldown', $email),
            self::codeLimitKey('hourly', $email),
        ];
    }

    private function codeSeconds(): int
    {
        return (int) config('raqam.auth.code_minutes') * 60;
    }

    private function maxAttempts(): int
    {
        return (int) config('raqam.auth.code_attempts');
    }

    private static function decoyHash(): string
    {
        return self::$decoyHash ??= Hash::make(bin2hex(random_bytes(16)));
    }
}
