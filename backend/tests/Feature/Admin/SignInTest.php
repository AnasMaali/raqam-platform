<?php

use App\Auth\AdminSignIn;
use App\Filament\Pages\Auth\AdminLogin;
use App\Filament\Pages\Auth\AdminLoginCode;
use App\Mail\AdminLoginCode as CodeMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->withoutDefer();
});

function codeUrl(): string
{
    return route('filament.admin.auth.login.code');
}

function staffUser(array $attributes = []): User
{
    return User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery', ...$attributes]);
}

/** Runs step 1 the way the browser does and returns the Livewire test object. */
function submitPassword(string $email = 'admin@raqam.ps', string $password = 'correct horse battery')
{
    return Livewire::test(AdminLogin::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
}

/** The code that was e-mailed to the last request. */
function mailedCode(): string
{
    return Mail::sent(CodeMail::class)->last()->code;
}

function submitCode(string $code)
{
    return Livewire::test(AdminLoginCode::class)->set('data.code', $code)->call('verify');
}

test('a correct password leads to the code page and e-mails a code', function () {
    staffUser();

    submitPassword()->assertHasNoFormErrors()->assertRedirect(codeUrl());

    Mail::assertSent(CodeMail::class, 1);
    expect(mailedCode())->toMatch('/^\d{6}$/')->and(DB::table('verification_codes')->count())->toBe(1);
    $this->assertGuest();
});

test('every kind of wrong or refused sign-in looks the same and sends nothing', function (string $email, string $password, ?callable $arrange) {
    staffUser();
    User::factory()->create(['email' => 'customer@raqam.ps', 'password_hash' => 'correct horse battery']);
    $arrange && $arrange();

    submitPassword($email, $password)->assertHasNoFormErrors()->assertRedirect(codeUrl());

    Mail::assertNothingSent();
    expect(DB::table('verification_codes')->count())->toBe(0);
    $this->assertGuest();
})->with([
    'wrong password' => ['admin@raqam.ps', 'wrong horse battery', null],
    'unknown e-mail' => ['nobody@raqam.ps', 'correct horse battery', null],
    'customer account' => ['customer@raqam.ps', 'correct horse battery', null],
    'inactive staff' => ['admin@raqam.ps', 'correct horse battery', fn () => User::where('email', 'admin@raqam.ps')->update(['is_active' => false])],
    'deleted staff' => ['admin@raqam.ps', 'correct horse battery', fn () => User::where('email', 'admin@raqam.ps')->update(['deleted_at' => now()])],
    // Decision 2026-10-25: a role is needed, and while multi_admin is off only «admin» counts.
    'staff without a role' => ['admin@raqam.ps', 'correct horse battery', fn () => DB::table('user_roles')->delete()],
    'staff with another role, multi_admin off' => ['admin@raqam.ps', 'correct horse battery', function () {
        DB::table('user_roles')->delete();
        $editor = DB::table('roles')->insertGetId(['code' => 'editor', 'name_ar' => 'محرر']);
        DB::table('user_roles')->insert(['user_id' => User::where('email', 'admin@raqam.ps')->value('id'), 'role_id' => $editor]);
    }],
]);

test('the code page shows the same message and form whatever the outcome', function () {
    staffUser();

    submitPassword('admin@raqam.ps', 'wrong horse battery');
    $wrong = $this->get(codeUrl())->assertOk()->getContent();

    $this->flushSession();
    Mail::fake();
    submitPassword();
    $right = $this->get(codeUrl())->assertOk()->assertSee('إذا كانت البيانات صحيحة وصلك رمز')->getContent();

    $strip = fn (string $html) => preg_replace('/(csrf|wire:id|wire:snapshot|nonce)[^>]*|\s+/', '', $html);
    expect(strlen($strip($wrong)))->toBe(strlen($strip($right)));
});

test('a password longer than 128 characters is rejected before it is hashed', function () {
    staffUser();

    submitPassword('admin@raqam.ps', str_repeat('a', 129))->assertHasFormErrors(['password']);

    Mail::assertNothingSent();
});

test('the e-mail is sent after the response, not during it', function () {
    staffUser();
    $this->withDefer();

    submitPassword();

    Mail::assertNothingSent();
    app(DeferredCallbackCollection::class)->invoke();
    Mail::assertSent(CodeMail::class, 1);
});

test('the right code signs the admin in, once, and starts a fresh session', function () {
    $user = staffUser();
    submitPassword();
    $before = session()->getId();

    submitCode(mailedCode())->assertHasNoFormErrors()->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before)
        ->and(DB::table('verification_codes')->whereNotNull('consumed_at')->count())->toBe(1)
        ->and(app(AdminSignIn::class)->hasPending())->toBeFalse();
});

test('a wrong code is refused and counted; five wrong codes end the attempt even for the right code', function () {
    staffUser();
    submitPassword();
    $right = mailedCode();
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $i) {
        submitCode($wrong)->assertHasFormErrors(['code']);
    }
    expect(DB::table('verification_codes')->value('attempts'))->toBe(4);

    submitCode($wrong)->assertRedirect();                  // 5th: the sign-in is over
    expect(app(AdminSignIn::class)->hasPending())->toBeFalse();

    expect(app(AdminSignIn::class)->verify($right))->toBeNull();   // no pending sign-in: refused
    $this->assertGuest();
});

test('the attempt counter lives in the code row too, so a restarted session cannot reset it', function () {
    staffUser();
    submitPassword();
    $code = mailedCode();

    DB::table('verification_codes')->update(['attempts' => 5]);   // five guesses already used on this code

    expect(app(AdminSignIn::class)->verify($code))->toBeNull();
    $this->assertGuest();
});

test('an expired code is refused', function () {
    staffUser();
    submitPassword();
    $code = mailedCode();

    $this->travel(11)->minutes();

    expect(app(AdminSignIn::class)->verify($code))->toBeNull();
    $this->assertGuest();
});

test('a code cannot be used twice', function () {
    $user = staffUser();
    submitPassword();
    $code = mailedCode();
    $state = session('admin_sign_in');

    submitCode($code);
    $this->assertAuthenticatedAs($user);
    auth()->logout();

    session()->put('admin_sign_in', $state);                // replay in the same session
    submitCode($code);

    $this->assertGuest();
});

/** As if the server clock was stepped back by NTP after the codes were written: they now look written in the future. */
function clockSteppedBackAfterCodes(): void
{
    DB::table('verification_codes')->update(['created_at' => DB::raw("now() + interval '2 seconds'")]);
}

test('a clock stepped back between the code and its use does not break the sign-in', function () {
    $user = staffUser();
    submitPassword();
    clockSteppedBackAfterCodes();

    submitCode(mailedCode())->assertHasNoFormErrors()->assertRedirect();

    $this->assertAuthenticatedAs($user);
    $row = DB::table('verification_codes')->first();
    expect($row->consumed_at)->toBe($row->created_at);       // greatest(now(), created_at), never before it
});

test('a clock stepped back before a resend still lets the new code replace the old one', function () {
    staffUser();
    submitPassword();
    $this->travel(61)->seconds();
    clockSteppedBackAfterCodes();

    expect(app(AdminSignIn::class)->resend())->toBe('sent');

    Mail::assertSent(CodeMail::class, 2);
    expect(DB::table('verification_codes')->whereNull('consumed_at')->count())->toBe(1)
        ->and(DB::table('verification_codes')->count())->toBe(2);
});

test('a code only works in the session that entered the password', function () {
    staffUser();
    submitPassword();
    $code = mailedCode();

    session()->put('admin_sign_in.binding', bin2hex(random_bytes(32)));   // as if it were another session
    submitCode($code);

    $this->assertGuest();
});

test('a new code replaces the older ones', function () {
    staffUser();
    submitPassword();
    $first = mailedCode();

    $this->travel(61)->seconds();
    Livewire::test(AdminLoginCode::class)->call('resend');
    $second = mailedCode();

    expect(DB::table('verification_codes')->whereNull('consumed_at')->count())->toBe(1);
    if ($first !== $second) {
        submitCode($first);
        $this->assertGuest();
    }
});

test('the code is stored as a keyed HMAC and not as the plain code', function () {
    staffUser();
    submitPassword();
    $code = mailedCode();
    $hash = DB::table('verification_codes')->value('code_hash');
    $binding = session('admin_sign_in.binding');

    expect($hash)->not->toContain($code)
        ->and($hash)->toBe(hash_hmac('sha256', "admin_login|admin@raqam.ps|{$binding}|{$code}", config('raqam.auth.code_key')))
        ->and($hash)->not->toBe(hash('sha256', $code));

    config(['raqam.auth.code_key' => 'another-key-entirely-0123456789abcdef']);
    submitCode($code);
    $this->assertGuest();
});

test('there is no code key, no code', function () {
    staffUser();
    config(['raqam.auth.code_key' => null]);
    Log::spy();

    submitPassword();

    Mail::assertNothingSent();
    expect(DB::table('verification_codes')->count())->toBe(0);
});

test('a code can be requested again only after 60 seconds, at most 3 times an hour', function () {
    staffUser();
    $signIn = app(AdminSignIn::class);
    submitPassword();                                       // 1st code

    expect($signIn->resend())->toBe('too_soon');
    Mail::assertSent(CodeMail::class, 1);

    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('sent');                // 2nd
    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('sent');                // 3rd
    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('limit');               // refused: 3 an hour
    Mail::assertSent(CodeMail::class, 3);

    $this->travel(61)->minutes();
    expect($signIn->resend())->toBe('sent');
    Mail::assertSent(CodeMail::class, 4);
});

test('the resend button reports the wait and the limit', function () {
    staffUser();
    submitPassword();

    Livewire::test(AdminLoginCode::class)->call('resend')->assertNotified();
    Mail::assertSent(CodeMail::class, 1);
});

test('the resend limits count the same for an unknown e-mail', function () {
    submitPassword('nobody@raqam.ps', 'whatever password');

    expect(app(AdminSignIn::class)->secondsUntilResend())->toBeGreaterThan(0);
    Livewire::test(AdminLoginCode::class)->call('resend');
    Mail::assertNothingSent();
});

test('the code page needs a pending sign-in', function () {
    Livewire::test(AdminLoginCode::class)->assertRedirect(Filament::getLoginUrl());
});
