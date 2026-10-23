<?php

namespace App\Newsletter;

use App\Contact\ContactInbox;
use App\Jobs\SyncSubscriberToBrevo;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Support\TrackingHash;
use App\Support\Turnstile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Timebox;
use RuntimeException;

/**
 * Subscribing to the newsletter (19.1–19.3, 19.9). The answer never says whether an address is subscribed: every
 * request gets a "challenge" and, if the limits allow, a 6-digit code by e-mail (30 minutes, 5 tries, a new one after
 * a minute), the same work happening inside a Timebox either way.
 *
 * The API has no session, so the code is bound to the challenge instead: the challenge is encrypted by us, carries the
 * address, the consent facts (when, where, which form) and a random value, and the code is an HMAC of the address, that
 * value and the digits. A code is good only with the challenge it was sent for. Nothing about the person is stored
 * until they confirm; the consent facts are written then, so a stranger cannot rewrite those of a subscriber.
 *
 * One address gets at most 3 codes an hour and 6 a day whoever asks (decision 2026-10-23); beyond that the answer is
 * the same and nothing is sent.
 */
final class NewsletterSignup
{
    public const PLACEMENTS = ['footer', 'post_end', 'newsletter_page'];

    /**
     * @param  array<string, mixed>  $input  email, name, placement, path, turnstile_token
     *
     * @throws NewsletterRefused
     */
    public function request(array $input, string $visitorIp): string
    {
        $clean = $this->validate($input);
        $this->limitVisitor('requests', $visitorIp, (int) config('raqam.newsletter.requests_per_visitor_hour'));
        if (! Turnstile::passes(is_string($input['turnstile_token'] ?? null) ? $input['turnstile_token'] : null, $visitorIp, 'newsletter')) {
            throw new NewsletterRefused('turnstile_failed', 'تعذّر التحقق من أنك لست روبوتاً؛ أعد المحاولة.');
        }
        $this->hitVisitor('requests', $visitorIp);

        $challenge = [
            'e' => $clean['email'], 'n' => $clean['name'], 'p' => $clean['placement'], 's' => $clean['path'],
            'i' => TrackingHash::of(TrackingHash::network($visitorIp)), 't' => now()->getTimestamp(), 'r' => bin2hex(random_bytes(16)),
        ];
        $this->timebox(fn () => $this->issueCode($challenge));

        return Crypt::encryptString(json_encode($challenge, JSON_UNESCAPED_UNICODE));
    }

    /** A new code for the same challenge, a minute after the last one (the same answer whether or not it went). */
    public function resend(string $token, string $visitorIp): string
    {
        $challenge = $this->challenge($token);
        $this->limitVisitor('requests', $visitorIp, (int) config('raqam.newsletter.requests_per_visitor_hour'));
        $this->hitVisitor('requests', $visitorIp);
        $this->timebox(fn () => $this->issueCode($challenge));

        return $token;
    }

    /** @throws NewsletterRefused bad_code or expired */
    public function confirm(string $token, string $code, string $visitorIp): void
    {
        $this->limitVisitor('confirms', $visitorIp, (int) config('raqam.newsletter.confirms_per_visitor_hour'));
        $this->hitVisitor('confirms', $visitorIp);
        $challenge = $this->challenge($token);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new NewsletterRefused('bad_code', 'الرمز غير صحيح.');
        }

        $result = $this->timebox(fn () => DB::transaction(function () use ($challenge, $code) {
            $open = DB::table('verification_codes')->where('purpose', 'newsletter_confirm')->where('email', $challenge['e'])->whereNull('consumed_at');
            $row = (clone $open)->where('expires_at', '>', DB::raw('now()'))->orderByDesc('id')->lockForUpdate()->first();
            if (! $row) {
                return (clone $open)->exists() ? 'expired' : 'bad_code';
            }
            if ($row->attempts >= (int) config('raqam.newsletter.code_attempts')) {
                return 'bad_code';
            }
            if (! hash_equals($row->code_hash, self::hash($challenge['e'], $challenge['r'], $code))) {
                DB::table('verification_codes')->where('id', $row->id)->increment('attempts');

                return 'bad_code';
            }
            // The schema requires consumed_at >= created_at; greatest() keeps a clock stepped back from failing it.
            DB::table('verification_codes')->where('id', $row->id)->update(['consumed_at' => DB::raw('greatest(now(), created_at)')]);
            $this->subscribe($challenge);

            return 'ok';
        }));

        if ($result !== 'ok') {
            throw $result === 'expired'
                ? new NewsletterRefused('expired', 'انتهت صلاحية الرمز؛ اطلب رمزاً جديداً.')
                : new NewsletterRefused('bad_code', 'الرمز غير صحيح.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{email: string, name: ?string, placement: string, path: ?string}
     */
    public function validate(array $input): array
    {
        $text = fn ($v) => is_string($v) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $v)) : $v;
        $data = ['email' => $text($input['email'] ?? null), 'name' => $text($input['name'] ?? null) ?: null, 'placement' => $input['placement'] ?? null, 'path' => $text($input['path'] ?? null) ?: null];
        $raw = $input['email'] ?? null;

        $validator = Validator::make($data, [
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
            'placement' => ['required', 'string', 'in:'.implode(',', self::PLACEMENTS)],
            'path' => ['nullable', 'string', 'max:500', 'regex:~^/(?!/)[^\s\\\\]*$~'],
        ], [
            'email.*' => 'البريد الإلكتروني غير صحيح.',
            'name.*' => 'الاسم غير صحيح (2–120 حرفاً).',
            'placement.*' => 'مكان الاشتراك غير معروف.',
            'path.*' => 'الصفحة غير صحيحة.',
        ]);
        $validator->after(function ($v) use ($data, $raw) {
            if (is_string($raw) && preg_match('/[\r\n]/', $raw) === 1) {
                $v->errors()->add('email', 'البريد الإلكتروني غير صحيح.');
            }
            if (is_string($data['name']) && ContactInbox::looksLikeLink($data['name'])) {
                $v->errors()->add('name', 'الاسم لا يمكن أن يحتوي رابطاً أو بريداً إلكترونياً.');
            }
        });
        $valid = $validator->validate();

        return ['email' => $valid['email'], 'name' => $valid['name'] ?? null, 'placement' => $valid['placement'], 'path' => $valid['path'] ?? null];
    }

    /** The code of a challenge: HMAC under the code key (not APP_KEY), so a leaked database cannot be used to guess it. */
    public static function hash(string $email, string $nonce, string $code): string
    {
        $key = config('raqam.auth.code_key') ?: throw new RuntimeException('ADMIN_CODE_KEY is not set; codes cannot be made safely.');

        return hash_hmac('sha256', 'newsletter|'.mb_strtolower($email).'|'.$nonce.'|'.$code, (string) $key);
    }

    /** @param  array<string, mixed>  $challenge */
    private function issueCode(array $challenge): void
    {
        $email = (string) $challenge['e'];
        $who = hash_hmac('sha256', mb_strtolower($email), (string) config('app.key'));   // the address never sits in a Redis key
        $cooldown = "newsletter:cooldown:{$who}";
        $hour = "newsletter:codes-hour:{$who}";
        $day = "newsletter:codes-day:{$who}";
        if (RateLimiter::tooManyAttempts($cooldown, 1)
            || RateLimiter::tooManyAttempts($hour, (int) config('raqam.newsletter.codes_per_email_hour'))
            || RateLimiter::tooManyAttempts($day, (int) config('raqam.newsletter.codes_per_email_day'))) {
            return;
        }
        RateLimiter::hit($cooldown, (int) config('raqam.newsletter.resend_seconds'));
        RateLimiter::hit($hour, 3600);
        RateLimiter::hit($day, 86400);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) config('raqam.newsletter.code_minutes');
        DB::transaction(function () use ($email, $challenge, $code, $minutes) {
            DB::table('verification_codes')->where('purpose', 'newsletter_confirm')->where('email', $email)->whereNull('consumed_at')
                ->update(['consumed_at' => DB::raw('greatest(now(), created_at)')]);   // a new code replaces the old ones
            DB::table('verification_codes')->insert([
                'purpose' => 'newsletter_confirm', 'email' => $email, 'code_hash' => self::hash($email, (string) $challenge['r'], $code),
                'expires_at' => DB::raw("now() + make_interval(mins => {$minutes})"),
            ]);
            SystemMail::queue(new TemplatedEmail('visitor_newsletter_code', ['الرمز' => $code, 'الدقائق' => $minutes]), $email);
        });
    }

    /** @param  array<string, mixed>  $c */
    private function subscribe(array $c): void
    {
        DB::statement(
            "INSERT INTO subscribers (email, name, status, source_path, source_placement, consent_ip_hash, subscribed_at, confirmed_at)
             VALUES (?, ?, 'active', ?, ?, ?, to_timestamp(?), now())
             ON CONFLICT (email) DO UPDATE SET name = COALESCE(EXCLUDED.name, subscribers.name), status = 'active',
                 source_path = EXCLUDED.source_path, source_placement = EXCLUDED.source_placement, consent_ip_hash = EXCLUDED.consent_ip_hash,
                 subscribed_at = EXCLUDED.subscribed_at, confirmed_at = now(), unsubscribed_at = NULL, updated_at = now()",
            [$c['e'], $c['n'], $c['s'], $c['p'], $c['i'], (int) $c['t']],
        );
        SyncSubscriberToBrevo::queueFor((string) $c['e']);
    }

    /** @return array<string, mixed> @throws NewsletterRefused */
    private function challenge(string $token): array
    {
        try {
            $c = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $c = null;
        }
        $fresh = is_array($c) && is_int($c['t'] ?? null) && now()->getTimestamp() - $c['t'] <= ((int) config('raqam.newsletter.code_minutes') + 5) * 60;
        if (! $fresh || ! is_string($c['e'] ?? null) || ! is_string($c['r'] ?? null) || ! in_array($c['p'] ?? null, self::PLACEMENTS, true)) {
            throw new NewsletterRefused('expired', 'انتهت صلاحية الطلب؛ اشترك من جديد.');
        }

        return $c;
    }

    private function limitVisitor(string $what, string $ip, int $max): void
    {
        if (RateLimiter::tooManyAttempts("newsletter:{$what}:".TrackingHash::visitor($ip), $max)) {
            throw new NewsletterRefused('too_many', 'طلبات كثيرة؛ حاول بعد قليل.', 429);
        }
    }

    private function hitVisitor(string $what, string $ip): void
    {
        RateLimiter::hit("newsletter:{$what}:".TrackingHash::visitor($ip), 3600);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function timebox(callable $work): mixed
    {
        return (new Timebox)->call(fn (Timebox $box) => $work(), (int) config('raqam.newsletter.timebox_ms') * 1000);
    }
}
