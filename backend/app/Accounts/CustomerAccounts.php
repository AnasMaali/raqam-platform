<?php

namespace App\Accounts;

use App\Auth\AdminPassword;
use App\Auth\LoginAttempts;
use App\Contact\ContactInbox;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\TrackingHash;
use App\Support\Turnstile;
use App\Support\VisitorText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * Customer accounts (decision 17), switched off until `customer_accounts` is on. The same users table, type `customer`;
 * a customer never enters the panel (it takes live, active staff only).
 *
 * Registering stores nothing until the e-mail code is entered: the name, phone and password hash travel inside the
 * encrypted challenge, so nobody can claim or overwrite an address they cannot read. The answers never say whether an
 * address has an account. Signing in has the admin's limits (5 a minute per address and visitor, 20 an hour per address,
 * 30 an hour per visitor) and one general message; a reset code lasts an hour and is used once.
 */
final class CustomerAccounts
{
    public const BAD_LOGIN = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';

    private static ?string $decoy = null;

    public function __construct(private AccountCodes $codes, private CustomerSessions $sessions) {}

    /** @param  array<string, mixed>  $input  name, email, phone, password, turnstile_token @return string the challenge */
    public function register(array $input, string $visitorIp): string
    {
        $clean = $this->validate($input, ['name', 'email', 'phone', 'password']);
        $this->limitVisitor('register', $visitorIp, (int) config('raqam.accounts.requests_per_visitor_hour'));
        $this->turnstile($input, $visitorIp, 'register');

        return $this->box(function () use ($clean) {
            $taken = User::query()->where('email', $clean['email'])->exists();

            // The password is hashed now and carried, encrypted, to the step after the code.
            return $this->codes->challenge('account_verify', $clean['email'],
                ['n' => $clean['name'], 'ph' => $clean['phone'], 'h' => Hash::make($clean['password'])], send: ! $taken);
        });
    }

    public function resendRegistration(string $challenge, string $visitorIp): string
    {
        $this->limitVisitor('register', $visitorIp, (int) config('raqam.accounts.requests_per_visitor_hour'));
        $this->box(function () use ($challenge) {
            $email = $this->peek($challenge, 'account_verify');
            $this->codes->resend($challenge, 'account_verify', send: $email !== null && ! User::query()->where('email', $email)->exists());
        });

        return $challenge;
    }

    /** The code of a registration: the account is made, verified, and signed in. @return string the session token */
    public function confirmRegistration(string $challenge, string $code, string $visitorIp): string
    {
        $this->limitVisitor('confirm', $visitorIp, (int) config('raqam.accounts.confirms_per_visitor_hour'));
        $user = $this->box(fn () => DB::transaction(function () use ($challenge, $code) {
            $c = $this->codes->consume($challenge, 'account_verify', $code);
            if (User::query()->where('email', $c['e'])->exists()) {
                // Only the owner of the mailbox gets this far, so saying it reveals nothing to a stranger.
                throw new AccountRefused('exists', 'هذا البريد مسجّل؛ سجّل الدخول أو استعد كلمة المرور.');
            }
            $user = new User(['name' => $c['d']['n'], 'email' => $c['e'], 'phone' => $c['d']['ph']]);
            $user->forceFill(['user_type' => 'customer', 'email_verified_at' => now()]);
            $user->setRawAttributes([...$user->getAttributes(), 'password_hash' => $c['d']['h']]);   // already hashed at registration
            $user->save();

            return $user;
        }));

        return $this->sessions->open($user);
    }

    /** @return string the session token @throws AccountRefused */
    public function login(mixed $email, #[\SensitiveParameter] mixed $password, string $visitorIp): string
    {
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $password = is_string($password) ? $password : '';
        $this->throttleLogin($email, $visitorIp);

        $user = $this->box(function () use ($email, $password, $visitorIp) {
            $user = $email === '' || strlen($password) > AdminPassword::MAX ? null : User::query()->where('email', $email)->first();
            $matches = Hash::check($password, $user?->password_hash ?? self::decoy());
            $allowed = $user !== null && $matches && $user->user_type === 'customer' && $user->is_active;
            $this->attempt($email, $user?->user_type === 'customer' ? $user->id : null,
                $allowed ? null : ($user && $matches && $user->user_type === 'customer' ? LoginAttempts::INACTIVE : LoginAttempts::BAD_CREDENTIALS), $visitorIp);

            return $allowed ? $user : null;
        });
        if ($user === null) {
            throw new AccountRefused('bad_credentials', self::BAD_LOGIN);
        }

        return $this->sessions->open($user);
    }

    /** @return string the challenge (the same answer whether or not the address has an account) */
    public function forgotPassword(array $input, string $visitorIp): string
    {
        $clean = $this->validate($input, ['email']);
        $this->limitVisitor('forgot', $visitorIp, (int) config('raqam.accounts.requests_per_visitor_hour'));
        $this->turnstile($input, $visitorIp, 'password_reset');

        return $this->box(fn () => $this->codes->challenge('password_reset', $clean['email'], [], send: $this->customer($clean['email']) !== null));
    }

    public function resetPassword(string $challenge, string $code, #[\SensitiveParameter] mixed $password, string $visitorIp): void
    {
        $this->limitVisitor('confirm', $visitorIp, (int) config('raqam.accounts.confirms_per_visitor_hour'));
        $clean = $this->validate(['password' => $password], ['password']);
        $user = $this->box(fn () => DB::transaction(function () use ($challenge, $code, $clean) {
            $c = $this->codes->consume($challenge, 'password_reset', $code);
            $user = $this->customer($c['e']) ?? throw new AccountRefused('bad_code', 'الرمز غير صحيح.');
            $user->forceFill(['password_hash' => $clean['password']])->save();   // every session ends with the old password

            return $user;
        }));
        $this->passwordChangedMail($user);
    }

    /** @return array{name: string, email: string, phone: ?string, created_at: string} */
    public function profile(User $user): array
    {
        return ['name' => $user->name, 'email' => (string) $user->email, 'phone' => $user->phone, 'created_at' => $user->created_at->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /** @param  array<string, mixed>  $input  name, phone */
    public function updateProfile(User $user, array $input): void
    {
        $clean = $this->validate($input, ['name', 'phone']);
        $user->forceFill(['name' => $clean['name'], 'phone' => $clean['phone']])->save();
    }

    /** @return string a new session token (the others end with the old password) */
    public function changePassword(User $user, #[\SensitiveParameter] mixed $current, #[\SensitiveParameter] mixed $new): string
    {
        $key = 'account:password:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new AccountRefused('too_many', 'محاولات كثيرة؛ حاول بعد دقيقة.', 429);
        }
        RateLimiter::hit($key, 60);
        if (! is_string($current) || strlen($current) > AdminPassword::MAX || ! Hash::check($current, $user->password_hash)) {
            throw new AccountRefused('bad_credentials', 'كلمة المرور الحالية غير صحيحة.');
        }
        $clean = $this->validate(['password' => $new], ['password']);
        if (Hash::check($clean['password'], $user->password_hash)) {
            throw new AccountRefused('same_password', 'كلمة المرور الجديدة يجب أن تختلف عن الحالية.');
        }
        $user->forceFill(['password_hash' => $clean['password']])->save();
        $this->passwordChangedMail($user);

        return $this->sessions->open($user->fresh());
    }

    /**
     * Deleting one's own account (17.7): the account and everything it holds go, and the booking contact of the same
     * address becomes anonymous, so the bookings stay for the accounts without a name. Invoices keep what they were
     * issued with (a legal record the database never lets change). Refused while a booking of this person is open.
     */
    public function delete(User $user, #[\SensitiveParameter] mixed $password): void
    {
        if (! is_string($password) || strlen($password) > AdminPassword::MAX || ! Hash::check($password, $user->password_hash)) {
            throw new AccountRefused('bad_credentials', 'كلمة المرور غير صحيحة.');
        }
        DB::transaction(function () use ($user) {
            $email = (string) $user->email;
            $customer = DB::table('customers')->where('email', $email)->lockForUpdate()->value('id');
            $open = DB::table('bookings as b')->join('booking_statuses as s', 's.code', '=', 'b.status_code')->where('s.is_terminal', false)
                ->whereNull('b.deleted_at')->where(fn ($q) => $q->where('b.user_id', $user->id)->orWhere('b.customer_id', $customer))->exists();
            if ($open) {
                throw new AccountRefused('open_booking', 'لديك حجز لم ينتهِ بعد؛ تواصل معنا لإنهائه أو إلغائه قبل حذف الحساب.');
            }

            DB::table('saved_posts')->where('user_id', $user->id)->delete();
            DB::table('followed_categories')->where('user_id', $user->id)->delete();
            DB::table('followed_sections')->where('user_id', $user->id)->delete();
            DB::table('user_devices')->where('user_id', $user->id)->delete();
            DB::table('bookings')->where('user_id', $user->id)->update(['user_id' => null]);
            DB::table('verification_codes')->where('email', $email)->delete();
            $gone = "deleted-{$user->id}@deleted.invalid";
            DB::table('login_attempts')->where('user_id', $user->id)->orWhere('email', $email)->update(['email' => $gone]);
            DB::table('email_log')->where('recipient', $email)->update(['recipient' => $gone]);
            if ($customer !== null) {
                DB::table('customers')->where('id', $customer)->update(['name' => 'عميل محذوف', 'email' => "deleted-customer-{$customer}@deleted.invalid",
                    'phone' => null, 'company_name' => null, 'updated_at' => now()]);
            }
            DB::table('users')->where('id', $user->id)->update(['name' => 'حساب محذوف', 'email' => $gone, 'phone' => null,
                'password_hash' => Hash::make(Str::random(64)), 'remember_token' => null, 'email_verified_at' => null,
                'is_active' => false, 'deleted_at' => now()]);
        });
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function validate(array $input, array $fields): array
    {
        $text = fn ($v) => is_string($v) ? VisitorText::clean($v, keepNewlines: false) : $v;
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = $f === 'password' ? ($input[$f] ?? null) : ($text($input[$f] ?? null) ?: null);
        }
        $rules = ['name' => ['required', 'string', 'min:2', 'max:120'], 'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            'phone' => ['nullable', 'string', 'max:30'], 'password' => AdminPassword::rules()];
        $validator = Validator::make($data, array_intersect_key($rules, $data), [
            'name.*' => 'اكتب اسمك (2–120 حرفاً).', 'email.*' => 'البريد الإلكتروني غير صحيح.', 'phone.*' => 'رقم الهاتف غير صحيح.', ...AdminPassword::messages(),
        ]);
        $validator->after(function ($v) use ($data) {
            if (is_string($data['name'] ?? null) && ContactInbox::looksLikeLink($data['name'])) {
                $v->errors()->add('name', 'الاسم لا يمكن أن يحتوي رابطاً أو بريداً إلكترونياً.');
            }
            if (is_string($data['phone'] ?? null) && PhoneNumber::e164($data['phone']) === null) {
                $v->errors()->add('phone', 'اكتب الرقم بالصيغة الدولية، مثال: +970599123456');
            }
        });
        $valid = $validator->validate();
        if (array_key_exists('phone', $valid)) {
            $valid['phone'] = PhoneNumber::e164($valid['phone']);
        }
        if (isset($valid['email'])) {
            $valid['email'] = Str::lower($valid['email']);
        }

        return $valid;
    }

    private function customer(string $email): ?User
    {
        return User::query()->where('email', $email)->where('user_type', 'customer')->where('is_active', true)->first();
    }

    private function peek(string $challenge, string $purpose): ?string
    {
        try {
            return $this->codes->resend($challenge, $purpose, send: false)['e'];
        } catch (AccountRefused) {
            return null;
        }
    }

    private function throttleLogin(string $email, string $ip): void
    {
        $visitor = TrackingHash::visitor($ip);
        $limits = [['account-login:pair:'.hash('sha256', $email.'|'.$visitor), (int) config('raqam.auth.attempts_per_minute'), 60],
            ['account-login:email:'.hash('sha256', $email), (int) config('raqam.auth.attempts_per_email_hour'), 3600],
            ['account-login:ip:'.$visitor, (int) config('raqam.auth.attempts_per_ip_hour'), 3600]];
        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                if (RateLimiter::attempt('account-login:logged:'.$limits[0][0], 1, fn () => true, 60)) {
                    $this->attempt($email, null, LoginAttempts::THROTTLED, $ip);
                }
                throw new AccountRefused('too_many', 'محاولات كثيرة؛ حاول بعد قليل.', 429);
            }
        }
        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /** login_attempts (22.16) with the visitor's address as the site saw it (HMAC only). */
    private function attempt(string $email, ?int $userId, ?string $failure, string $ip): void
    {
        if (strlen($email) > 254 || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) !== 1) {
            return;
        }
        DB::table('login_attempts')->insert(['email' => $email, 'user_id' => $userId, 'succeeded' => $failure === null,
            'failure_reason' => $failure, 'ip_hash' => TrackingHash::of(TrackingHash::network($ip))]);
    }

    private function limitVisitor(string $what, string $ip, int $max): void
    {
        $key = "account:{$what}:".TrackingHash::visitor($ip);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new AccountRefused('too_many', 'طلبات كثيرة؛ حاول بعد قليل.', 429);
        }
        RateLimiter::hit($key, 3600);
    }

    /** @param  array<string, mixed>  $input */
    private function turnstile(array $input, string $ip, string $action): void
    {
        if (! Turnstile::passes(is_string($input['turnstile_token'] ?? null) ? $input['turnstile_token'] : null, $ip, $action)) {
            throw new AccountRefused('turnstile_failed', 'تعذّر التحقق من أنك لست روبوتاً؛ أعد المحاولة.');
        }
    }

    private function passwordChangedMail(User $user): void
    {
        SystemMail::queue(new TemplatedEmail('account_password_changed', ['الاسم' => $user->name]), (string) $user->email);
    }

    /** @template T @param callable(): T $work @return T */
    private function box(callable $work): mixed
    {
        return (new Timebox)->call(fn () => $work(), (int) config('raqam.accounts.timebox_ms') * 1000);
    }

    private static function decoy(): string
    {
        return self::$decoy ??= Hash::make(Str::random(32));
    }
}
