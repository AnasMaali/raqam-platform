<?php

use App\Auth\AdminSignIn;
use App\Booking\BookingStatuses;
use App\Mail\AdminLoginCode as CodeMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

const ACCOUNT_SITE_TOKEN = 'account-site-token';
const CUSTOMER_PASSWORD = 'a long unleaked passphrase';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => ACCOUNT_SITE_TOKEN, 'raqam.auth.code_key' => 'code-key', 'raqam.accounts.timebox_ms' => 1]);
    Cache::flush();
    fakePwned(['password123456']);
    DB::table('feature_flags')->insertOrIgnore(['key' => 'customer_accounts', 'description_ar' => 'حسابات العملاء', 'is_enabled' => false]);
    DB::table('feature_flags')->insertOrIgnore(['key' => 'booking', 'description_ar' => 'نظام الحجز الإلكتروني', 'is_enabled' => false]);
});

function accountsOn(bool $booking = false): void
{
    DB::table('feature_flags')->where('key', 'customer_accounts')->update(['is_enabled' => true]);
    DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => $booking]);
}

function accountHeaders(?string $token = null, string $ip = '203.0.113.7'): array
{
    return array_filter(['X-Raqam-Site-Token' => ACCOUNT_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => $ip, 'Authorization' => $token ? "Bearer {$token}" : null]);
}

function account(string $method, string $path, array $body = [], ?string $token = null, string $ip = '203.0.113.7')
{
    return test()->flushHeaders()->withHeaders(accountHeaders($token, $ip))->json($method, '/api/v1/account'.$path, $body);
}

/** The 6-digit code of the last message sent to an address. */
function lastCodeTo(string $email): ?string
{
    $mails = sentEmailsTo($email);

    return $mails === [] ? null : (preg_match('/\b(\d{6})\b/', strip_tags(end($mails)->getHtmlBody()), $m) ? $m[1] : null);
}

/** Registers and confirms an account; returns its session token. */
function signedUpCustomer(string $email = 'sami@example.com', string $password = CUSTOMER_PASSWORD): string
{
    $challenge = account('POST', '/register', ['name' => 'سامي', 'email' => $email, 'password' => $password])->assertStatus(202)->json('data.challenge');

    return account('POST', '/register/confirm', ['challenge' => $challenge, 'code' => lastCodeTo($email)])->assertStatus(201)->json('data.token');
}

// --- Switched off (17.1, 17.14) -------------------------------------------------------------------------------------

test('while customer accounts are off every account route is a 404', function () {
    accountsOn();
    $token = signedUpCustomer();
    DB::table('feature_flags')->where('key', 'customer_accounts')->update(['is_enabled' => false]);

    foreach ([['POST', '/register'], ['POST', '/register/confirm'], ['POST', '/login'], ['POST', '/password/forgot'], ['POST', '/password/reset'],
        ['POST', '/logout'], ['GET', '/profile'], ['PATCH', '/profile'], ['POST', '/password'], ['DELETE', ''], ['GET', '/saved'],
        ['PUT', '/saved/1'], ['GET', '/follows'], ['PUT', '/follows/category/1'], ['GET', '/bookings']] as [$method, $path]) {
        account($method, $path, ['email' => 'x@example.com'], $token)->assertNotFound();
    }
});

// --- Registering (17.2, 17.3) ---------------------------------------------------------------------------------------

test('registering stores nothing until the code is entered; then the account is a verified customer, signed in', function () {
    accountsOn();
    $challenge = account('POST', '/register', ['name' => 'سامي الخطيب', 'email' => 'Sami@Example.com', 'phone' => '+970 599 123 456', 'password' => CUSTOMER_PASSWORD])
        ->assertStatus(202)->json('data.challenge');
    expect(DB::table('users')->count())->toBe(0)->and(lastCodeTo('sami@example.com'))->toMatch('/^\d{6}$/')
        ->and($challenge)->not->toContain(CUSTOMER_PASSWORD)->not->toContain('sami');

    $token = account('POST', '/register/confirm', ['challenge' => $challenge, 'code' => lastCodeTo('sami@example.com')])->assertStatus(201)->json('data.token');
    $user = User::query()->sole();
    expect($user->user_type)->toBe('customer')->and((string) $user->email)->toBe('sami@example.com')->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check(CUSTOMER_PASSWORD, $user->password_hash))->toBeTrue()->and(DB::table('user_roles')->count())->toBe(0);
    account('GET', '/profile', token: $token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.name', 'سامي الخطيب')->assertJsonPath('data.email', 'sami@example.com');
});

test('the password is 10–128 characters and not in a public leak', function (string $password) {
    accountsOn();
    account('POST', '/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => $password])->assertStatus(422)
        ->assertJsonValidationErrors(['password'], 'error.details');
    expect(sentEmails())->toBe([]);
})->with(['nine chars' => ['123456789'], 'too long' => [str_repeat('x', 129)], 'leaked' => ['password123456']]);

test('an address that has an account gets the same answer and no code, and the code page says it only to its owner', function () {
    accountsOn();
    signedUpCustomer();
    insertUser('staff', 'admin@raqam.example');
    $before = count(sentEmails());

    foreach (['sami@example.com', 'admin@raqam.example'] as $email) {
        account('POST', '/register', ['name' => 'منتحل', 'email' => $email, 'password' => CUSTOMER_PASSWORD], ip: '198.51.100.'.rand(1, 200))
            ->assertStatus(202)->assertJsonStructure(['data' => ['challenge']]);
    }
    expect(count(sentEmails()))->toBe($before)->and(User::query()->count())->toBe(2);
});

test('somebody registering another person\'s address first cannot take it: the person\'s own code decides', function () {
    accountsOn();
    $attacker = account('POST', '/register', ['name' => 'منتحل', 'email' => 'sami@example.com', 'password' => 'attacker chosen phrase'], ip: '198.51.100.9')->json('data.challenge');
    $this->travel(61)->seconds();
    $own = account('POST', '/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->json('data.challenge');
    $code = lastCodeTo('sami@example.com');

    account('POST', '/register/confirm', ['challenge' => $attacker, 'code' => $code], ip: '198.51.100.9')->assertStatus(422);   // the code is bound to the person's challenge
    account('POST', '/register/confirm', ['challenge' => $own, 'code' => $code])->assertStatus(201);
    account('POST', '/login', ['email' => 'sami@example.com', 'password' => 'attacker chosen phrase'], ip: '198.51.100.9')->assertStatus(422);
    account('POST', '/login', ['email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->assertOk();
});

test('a code has 5 tries, is used once and expires', function () {
    accountsOn();
    $challenge = account('POST', '/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->json('data.challenge');
    $code = lastCodeTo('sami@example.com');
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $_) {
        account('POST', '/register/confirm', ['challenge' => $challenge, 'code' => $wrong])->assertStatus(422)->assertJsonPath('error.code', 'bad_code');
    }
    account('POST', '/register/confirm', ['challenge' => $challenge, 'code' => $code])->assertStatus(422);   // tries used up
    expect(User::query()->count())->toBe(0);

    $this->travel(61)->seconds();
    $challenge = account('POST', '/register/resend', ['challenge' => $challenge])->assertStatus(202)->json('data.challenge');
    DB::table('verification_codes')->whereNull('consumed_at')->update(['created_at' => DB::raw("created_at - interval '11 minutes'"), 'expires_at' => DB::raw("expires_at - interval '11 minutes'")]);
    account('POST', '/register/confirm', ['challenge' => $challenge, 'code' => lastCodeTo('sami@example.com')])->assertStatus(422)->assertJsonPath('error.code', 'expired');
});

// --- Signing in (17.4) ----------------------------------------------------------------------------------------------

test('signing in: one general message whatever was wrong, and the admin\'s limits', function () {
    accountsOn();
    signedUpCustomer();
    insertUser('staff', 'admin@raqam.example', ['password_hash' => Hash::make(CUSTOMER_PASSWORD)]);

    $token = account('POST', '/login', ['email' => 'SAMI@example.com', 'password' => CUSTOMER_PASSWORD])->assertOk()->json('data.token');
    account('GET', '/profile', token: $token)->assertOk();
    $wrong = account('POST', '/login', ['email' => 'sami@example.com', 'password' => 'not the password'], ip: '198.51.100.1')->assertStatus(422)->json('error');
    $unknown = account('POST', '/login', ['email' => 'nobody@example.com', 'password' => 'not the password'], ip: '198.51.100.2')->assertStatus(422)->json('error');
    $staff = account('POST', '/login', ['email' => 'admin@raqam.example', 'password' => CUSTOMER_PASSWORD], ip: '198.51.100.3')->assertStatus(422)->json('error');
    expect($wrong)->toBe($unknown)->toBe($staff)->toBe(['code' => 'bad_credentials', 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.']);

    foreach (range(1, 4) as $_) {
        account('POST', '/login', ['email' => 'sami@example.com', 'password' => 'not the password'], ip: '198.51.100.1');
    }
    account('POST', '/login', ['email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD], ip: '198.51.100.1')->assertStatus(429);   // 6th in the minute
    $rows = DB::table('login_attempts')->where('email', 'sami@example.com')->get();
    expect($rows->where('succeeded', true)->count())->toBe(1)->and($rows->where('failure_reason', 'throttled')->count())->toBe(1)
        ->and($rows->pluck('ip_hash')->filter()->count())->toBe($rows->count())
        ->and(json_encode($rows))->not->toContain('198.51.100.1');
});

test('a customer never enters the panel, even with the right password', function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->withoutDefer();
    $id = insertUser('customer', 'customer@example.com', ['password_hash' => Hash::make(CUSTOMER_PASSWORD), 'email_verified_at' => now()]);

    app(AdminSignIn::class)->begin('customer@example.com', CUSTOMER_PASSWORD);
    Mail::assertNothingSent();
    Mail::assertNotQueued(CodeMail::class);
    expect(User::query()->find($id)->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(DB::table('login_attempts')->where('email', 'customer@example.com')->value('succeeded'))->toBeFalse();

    accountsOn();
    $token = account('POST', '/login', ['email' => 'customer@example.com', 'password' => CUSTOMER_PASSWORD])->json('data.token');
    test()->flushHeaders()->withHeaders(['Authorization' => "Bearer {$token}"])->get('/'.Filament::getPanel('admin')->getPath())->assertRedirect();
    expect(auth()->check())->toBeFalse();
});

// --- Resetting the password (17.5) ----------------------------------------------------------------------------------

test('a reset code is sent only to an account, works once within the hour, ends every session and tells the customer', function () {
    accountsOn();
    $old = signedUpCustomer();
    $nobody = account('POST', '/password/forgot', ['email' => 'nobody@example.com'])->assertStatus(202)->json('data.challenge');
    expect($nobody)->toBeString()->and(sentEmailsTo('nobody@example.com'))->toBe([]);

    $challenge = account('POST', '/password/forgot', ['email' => 'sami@example.com'])->assertStatus(202)->json('data.challenge');
    $code = lastCodeTo('sami@example.com');
    DB::table('verification_codes')->where('purpose', 'password_reset')->update(['created_at' => DB::raw("created_at - interval '59 minutes'"), 'expires_at' => DB::raw("expires_at - interval '59 minutes'")]);
    account('POST', '/password/reset', ['challenge' => $challenge, 'code' => $code, 'password' => 'a brand new passphrase'])->assertOk();

    account('GET', '/profile', token: $old)->assertStatus(401);
    account('POST', '/login', ['email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->assertStatus(422);
    account('POST', '/login', ['email' => 'sami@example.com', 'password' => 'a brand new passphrase'])->assertOk();
    account('POST', '/password/reset', ['challenge' => $challenge, 'code' => $code, 'password' => 'yet another passphrase'])->assertStatus(422);
    expect(collect(sentEmailsTo('sami@example.com'))->map->getSubject()->last())->toBe('تم تغيير كلمة مرور حسابك في رَقَم');
});

test('a reset code older than an hour is refused', function () {
    accountsOn();
    signedUpCustomer();
    $challenge = account('POST', '/password/forgot', ['email' => 'sami@example.com'])->json('data.challenge');
    DB::table('verification_codes')->where('purpose', 'password_reset')->update(['created_at' => DB::raw("created_at - interval '61 minutes'"), 'expires_at' => DB::raw("expires_at - interval '61 minutes'")]);
    account('POST', '/password/reset', ['challenge' => $challenge, 'code' => lastCodeTo('sami@example.com'), 'password' => 'a brand new passphrase'])
        ->assertStatus(422)->assertJsonPath('error.code', 'expired');
});

// --- Profile (17.6) -------------------------------------------------------------------------------------------------

test('the profile changes name and phone; a password change needs the current one and ends the other sessions', function () {
    accountsOn();
    $token = signedUpCustomer();
    account('PATCH', '/profile', ['name' => 'سامي أحمد', 'phone' => '+970599000111'], $token)->assertOk()->assertJsonPath('data.phone', '+970599000111');
    account('PATCH', '/profile', ['name' => 'x', 'email' => 'other@example.com'], $token)->assertStatus(422);
    expect((string) User::query()->value('email'))->toBe('sami@example.com');

    account('POST', '/password', ['current_password' => 'wrong one here', 'password' => 'a brand new passphrase'], $token)->assertStatus(422);
    account('POST', '/password', ['current_password' => CUSTOMER_PASSWORD, 'password' => CUSTOMER_PASSWORD], $token)->assertStatus(422)->assertJsonPath('error.code', 'same_password');
    $new = account('POST', '/password', ['current_password' => CUSTOMER_PASSWORD, 'password' => 'a brand new passphrase'], $token)->assertOk()->json('data.token');
    account('GET', '/profile', token: $token)->assertStatus(401);
    account('GET', '/profile', token: $new)->assertOk();
    account('POST', '/logout', token: $new)->assertOk();
    account('GET', '/profile', token: $new)->assertStatus(401);
});

// --- Saved items and follows (17.9, 17.10) --------------------------------------------------------------------------

test('only public items are saved and listed; only public categories and sections are followed', function () {
    accountsOn();
    $token = signedUpCustomer();
    $live = insertPost(publishedPostValues());
    $draft = insertPost();

    account('PUT', "/saved/{$live}", token: $token)->assertOk();
    account('PUT', "/saved/{$live}", token: $token)->assertOk();
    account('PUT', "/saved/{$draft}", token: $token)->assertNotFound();
    expect(array_column(account('GET', '/saved', token: $token)->json('data'), 'id'))->toBe([$live]);
    DB::table('posts')->where('id', $live)->update(['status' => 'archived']);
    expect(account('GET', '/saved', token: $token)->json('data'))->toBe([]);
    account('DELETE', "/saved/{$live}", token: $token)->assertOk();
    expect(DB::table('saved_posts')->count())->toBe(0);

    $category = insertCategory('follow-energy');
    $hidden = insertCategory('follow-hidden');
    DB::table('categories')->where('id', $hidden)->update(['is_active' => false]);
    $section = DB::table('sections')->value('id');
    account('PUT', "/follows/category/{$category}", token: $token)->assertOk();
    account('PUT', "/follows/category/{$hidden}", token: $token)->assertNotFound();
    account('PUT', "/follows/section/{$section}", token: $token)->assertOk();
    $follows = account('GET', '/follows', token: $token)->json('data');
    expect(array_column($follows['categories'], 'id'))->toBe([$category])->and(array_column($follows['sections'], 'id'))->toBe([$section]);
    DB::table('sections')->where('id', $section)->update(['is_visible' => false]);   // hidden later: no longer listed
    expect(account('GET', '/follows', token: $token)->json('data.sections'))->toBe([]);
    account('DELETE', "/follows/category/{$category}", token: $token)->assertOk();
    expect(DB::table('followed_categories')->count())->toBe(0);
});

// --- «حجوزاتي» (17.8) ------------------------------------------------------------------------------------------------

test('a booking made while signed in is the account\'s; none made before or without signing in is linked', function () {
    accountsOn(booking: true);
    config(['raqam.turnstile.secret' => null]);
    $token = signedUpCustomer();
    insertBooking(['user_id' => null]);   // an older booking with no account
    insertService(['slug' => 'podcast-episode']);
    $form = ['service' => 'podcast-episode', 'name' => 'سامي', 'phone' => '+970599123456', 'email' => 'sami@example.com', 'preferred_contact' => 'email',
        'details' => 'تفاصيل طلب الحجز هنا.', 'consent' => true];
    test()->flushHeaders()->withHeaders(accountHeaders($token))->postJson('/api/v1/bookings', $form)->assertStatus(202);
    test()->flushHeaders()->withHeaders(accountHeaders(null, '198.51.100.4'))->postJson('/api/v1/bookings', [...$form, 'email' => 'other@example.com'])->assertStatus(202);

    $mine = account('GET', '/bookings', token: $token)->assertOk()->json('data');
    expect($mine)->toHaveCount(1)->and($mine[0]['service'])->toBe('حلقة بودكاست')->and($mine[0]['status']['code'])->toBe('new');

    DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => false]);
    account('GET', '/bookings', token: $token)->assertNotFound();
});

// --- Deleting one's own account (17.7) ------------------------------------------------------------------------------

test('deleting the account wipes the person and keeps the bookings without a name; refused while a booking is open', function () {
    accountsOn(booking: true);
    $token = signedUpCustomer();
    $user = User::query()->sole();
    $booking = insertBooking(['user_id' => $user->id]);
    $customer = DB::table('bookings')->where('id', $booking)->value('customer_id');
    DB::table('customers')->where('id', $customer)->update(['email' => 'sami@example.com']);
    $payment = insertPayment($booking);
    DB::table('invoices')->insert(['payment_id' => $payment, 'billed_name' => 'سامي', 'billed_email' => 'sami@example.com', 'currency_code' => 'USD']);
    DB::table('saved_posts')->insert(['user_id' => $user->id, 'post_id' => insertPost(publishedPostValues())]);

    account('POST', '/login', ['email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->assertOk();   // leaves a sign-in row with the address
    account('DELETE', '', ['password' => 'wrong password'], $token)->assertStatus(422);
    account('DELETE', '', ['password' => CUSTOMER_PASSWORD], $token)->assertStatus(422)->assertJsonPath('error.code', 'open_booking');

    app(BookingStatuses::class)->move($booking, 'cancelled');
    account('DELETE', '', ['password' => CUSTOMER_PASSWORD], $token)->assertOk();

    $gone = DB::table('users')->where('id', $user->id)->first();
    expect($gone->name)->toBe('حساب محذوف')->and($gone->email)->toBe("deleted-{$user->id}@deleted.invalid")->and($gone->phone)->toBeNull()
        ->and($gone->is_active)->toBeFalse()->and($gone->deleted_at)->not->toBeNull()->and(Hash::check(CUSTOMER_PASSWORD, $gone->password_hash))->toBeFalse();
    expect(DB::table('customers')->where('id', $customer)->first(['name', 'phone', 'company_name']))->toEqual((object) ['name' => 'عميل محذوف', 'phone' => null, 'company_name' => null])
        ->and(DB::table('bookings')->where('id', $booking)->first(['user_id', 'customer_id']))->toEqual((object) ['user_id' => null, 'customer_id' => $customer])
        ->and(DB::table('saved_posts')->count())->toBe(0)
        ->and(DB::table('invoices')->value('billed_email'))->toBe('sami@example.com');   // a legal record: never changed
    expect(DB::table('login_attempts')->where('email', 'sami@example.com')->count())->toBe(0)
        ->and(DB::table('email_log')->where('recipient', 'sami@example.com')->count())->toBe(0);
    account('GET', '/profile', token: $token)->assertStatus(401);

    $this->travel(61)->seconds();
    signedUpCustomer();   // the address is free again
});

test('a session is a 43-character token kept only as a hash, for 30 days at most', function () {
    accountsOn();
    $token = signedUpCustomer();
    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(Cache::has('account:session:'.hash('sha256', $token)))->toBeTrue()->and(Cache::has('account:session:'.$token))->toBeFalse();
    account('GET', '/profile', token: $token.'x')->assertStatus(401);
    DB::table('users')->update(['user_type' => 'staff']);   // a session is a customer's only, whatever happens to the row
    account('GET', '/profile', token: $token)->assertStatus(401);
    DB::table('users')->update(['user_type' => 'customer']);
    $this->travel(31)->days();
    account('GET', '/profile', token: $token)->assertStatus(401);
});

test('signed-in routes come from the site only', function () {
    accountsOn();
    $token = signedUpCustomer();
    test()->flushHeaders()->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/account/profile')->assertStatus(403);
    test()->flushHeaders()->withHeaders(['X-Raqam-Visitor-Ip' => '203.0.113.7'])->postJson('/api/v1/account/login', ['email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->assertStatus(403);
});

test('one address gets one code a minute, whoever asks, and a code cannot make a second account of an address', function () {
    accountsOn();
    $first = account('POST', '/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD])->json('data.challenge');
    account('POST', '/register', ['name' => 'سامي', 'email' => 'sami@example.com', 'password' => CUSTOMER_PASSWORD], ip: '198.51.100.8');
    expect(sentEmailsTo('sami@example.com'))->toHaveCount(1);

    insertUser('staff', 'sami@example.com');   // the address got an account meanwhile (here: an admin's)
    account('POST', '/register/confirm', ['challenge' => $first, 'code' => lastCodeTo('sami@example.com')])->assertStatus(422)->assertJsonPath('error.code', 'exists');
    expect(User::query()->where('user_type', 'customer')->count())->toBe(0);
});
