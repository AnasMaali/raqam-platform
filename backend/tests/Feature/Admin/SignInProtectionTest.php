<?php

use App\Auth\AdminSignIn;
use App\Filament\Pages\Auth\AdminLogin;
use App\Mail\AdminLoginCode as CodeMail;
use App\Models\User;
use App\Support\TrackingHash;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->withoutDefer();
    fromIp('203.0.113.9');
    User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);
});

function fromIp(string $ip): void
{
    request()->server->set('REMOTE_ADDR', $ip);
}

function signIn(string $email = 'admin@raqam.ps', string $password = 'correct horse battery'): ?int
{
    return app(AdminSignIn::class)->begin($email, $password);
}

function attempts(): Collection
{
    return DB::table('login_attempts')->orderBy('id')->get();
}

function wrongCode(): string
{
    return Mail::sent(CodeMail::class)->last()->code === '000000' ? '111111' : '000000';
}

// ---- what is recorded ---------------------------------------------------------------------------

test('a wrong password is recorded as bad_credentials with the account when it exists', function () {
    signIn(password: 'wrong horse battery');
    signIn('nobody@raqam.ps');

    expect(attempts()->map(fn ($a) => [$a->failure_reason, $a->succeeded, $a->user_id !== null])->all())
        ->toBe([['bad_credentials', false, true], ['bad_credentials', false, false]]);
});

test('a right password on an account that may not enter is recorded as inactive', function () {
    User::factory()->create(['email' => 'customer@raqam.ps', 'password_hash' => 'correct horse battery']);
    User::factory()->staff()->create(['email' => 'off@raqam.ps', 'password_hash' => 'correct horse battery', 'is_active' => false]);

    signIn('customer@raqam.ps');
    signIn('off@raqam.ps');

    expect(attempts()->pluck('failure_reason')->all())->toBe(['inactive', 'inactive']);
});

test('a wrong code is recorded as bad_code', function () {
    signIn();
    app(AdminSignIn::class)->verify(wrongCode());

    $row = attempts()->last();
    expect($row->failure_reason)->toBe('bad_code')->and($row->succeeded)->toBeFalse()->and($row->user_id)->not->toBeNull();
});

test('a code used after its time is recorded as expired_code', function () {
    signIn();
    $code = Mail::sent(CodeMail::class)->last()->code;

    $this->travel(11)->minutes();
    app(AdminSignIn::class)->verify($code);

    expect(attempts()->last()->failure_reason)->toBe('expired_code');
});

test('a code the database has expired is recorded as expired_code too', function () {
    signIn();
    $code = Mail::sent(CodeMail::class)->last()->code;
    DB::table('verification_codes')->update(['created_at' => now()->subMinutes(20), 'expires_at' => now()->subMinutes(10)]);

    app(AdminSignIn::class)->verify($code);

    expect(attempts()->last()->failure_reason)->toBe('expired_code');
    $this->assertGuest();
});

test('a completed sign-in is recorded as a success', function () {
    signIn();
    app(AdminSignIn::class)->verify(Mail::sent(CodeMail::class)->last()->code);

    $row = attempts()->last();
    expect($row->succeeded)->toBeTrue()->and($row->failure_reason)->toBeNull()->and($row->email)->toBe('admin@raqam.ps');
});

test('an e-mail the column would refuse does not break the sign-in', function () {
    expect(signIn('not an email', 'whatever'))->toBeNull();

    expect(attempts())->toHaveCount(0);
});

// ---- the IP is only ever an HMAC ----------------------------------------------------------------

test('the IP address is stored as an HMAC and nowhere else in the database or the limiter', function () {
    signIn(password: 'wrong horse battery');
    signIn();
    app(AdminSignIn::class)->verify('000000');

    $expected = hash_hmac('sha256', '203.0.113.9', config('raqam.tracking_key'));
    expect(attempts()->pluck('ip_hash')->unique()->all())->toBe([$expected])
        ->and($expected)->not->toBe(hash('sha256', '203.0.113.9'));

    $everything = json_encode([
        DB::table('login_attempts')->get(), DB::table('verification_codes')->get(), DB::table('audit_log')->get(),
    ]);
    expect($everything)->not->toContain('203.0.113.9');

    $store = Cache::store()->getStore();
    $keys = array_keys((fn () => $this->storage)->call($store));
    expect(implode('|', $keys))->not->toContain('203.0.113.9')->not->toContain('admin@raqam.ps');
});

test('without the tracking key nothing is recorded and the sign-in fails closed', function () {
    config(['raqam.tracking_key' => null]);

    expect(fn () => TrackingHash::of('203.0.113.9'))->toThrow(RuntimeException::class, 'TRACKING_HASH_KEY');
    expect(fn () => signIn())->toThrow(RuntimeException::class);
});

// ---- limits -------------------------------------------------------------------------------------

test('the sixth password in a minute from the same e-mail and IP is refused, even a right one', function () {
    foreach (range(1, 5) as $i) {
        expect(signIn(password: 'wrong horse battery'))->toBeNull();
    }
    Mail::fake();

    $wait = signIn();                                        // the right password, but too many tries

    expect($wait)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    Mail::assertNothingSent();
    expect(attempts()->pluck('failure_reason')->countBy()->all())->toBe(['bad_credentials' => 5, 'throttled' => 1]);

    $this->travel(61)->seconds();
    expect(signIn())->toBeNull();
    Mail::assertSent(CodeMail::class, 1);
});

test('a flood of refused requests leaves one throttled row a minute', function () {
    foreach (range(1, 5) as $i) {
        signIn(password: 'wrong horse battery');
    }
    foreach (range(1, 30) as $i) {
        signIn(password: 'wrong horse battery');
    }

    expect(attempts()->where('failure_reason', 'throttled'))->toHaveCount(1);
});

test('one e-mail is limited to 20 attempts an hour even from different IPs', function () {
    foreach (range(1, 20) as $i) {
        fromIp("198.51.100.{$i}");
        expect(signIn(password: 'wrong horse battery'))->toBeNull();
    }

    fromIp('198.51.100.99');
    expect(signIn())->toBeGreaterThan(0);

    $this->travel(61)->minutes();
    expect(signIn())->toBeNull();
});

test('one IP is limited to 30 attempts an hour even across different e-mails', function () {
    foreach (range(1, 30) as $i) {
        expect(signIn("someone{$i}@raqam.ps", 'whatever password'))->toBeNull();
    }

    expect(signIn('another@raqam.ps', 'whatever password'))->toBeGreaterThan(0)
        ->and(signIn())->toBeGreaterThan(0);                 // and the real admin from that IP

    fromIp('198.51.100.7');
    expect(signIn())->toBeNull();                            // from another IP the admin gets in
});

test('an unknown e-mail is limited exactly like a real one', function () {
    foreach (range(1, 5) as $i) {
        expect(signIn('nobody@raqam.ps', 'whatever password'))->toBeNull();
    }

    expect(signIn('nobody@raqam.ps', 'whatever password'))->toBeGreaterThan(0);
});

test('the page tells the visitor to wait and does not move on', function () {
    $page = fn () => Livewire::test(AdminLogin::class)
        ->fillForm(['email' => 'admin@raqam.ps', 'password' => 'wrong horse battery'])
        ->call('authenticate');

    foreach (range(1, 5) as $i) {
        $page()->assertRedirect();
    }

    $page()->assertNotified()->assertNoRedirect();
});
