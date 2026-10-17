<?php

use App\Auth\AdminSignIn;
use App\Mail\AdminLoginCode as CodeMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->url = '/'.config('raqam.admin_path');
    $this->admin = User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);
    request()->server->set('REMOTE_ADDR', '203.0.113.9');
    request()->headers->set('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0.0.0 Safari/537.36');
});

function auditRows(): Collection
{
    return DB::table('audit_log')->orderBy('id')->get();
}

function logoutReasons(): array
{
    return DB::table('audit_log_changes')->where('field', 'logout_reason')->orderBy('audit_id')->pluck('new_value')->all();
}

function signInWithCode(): void
{
    Mail::fake();
    test()->withoutDefer();
    $signIn = app(AdminSignIn::class);
    $signIn->begin('admin@raqam.ps', 'correct horse battery');
    $signIn->verify(Mail::sent(CodeMail::class)->last()->code);
}

// ---- login ------------------------------------------------------------------------------------

test('a completed sign-in is logged with who, when, from where (as an HMAC) and with what', function () {
    signInWithCode();

    $row = auditRows()->sole();
    expect($row->action)->toBe('login')
        ->and($row->actor_user_id)->toBe($this->admin->id)
        ->and($row->subject_table)->toBe('users')->and($row->subject_id)->toBe($this->admin->id)
        ->and($row->ip_hash)->toBe(hash_hmac('sha256', '203.0.113.9', config('raqam.tracking_key')))
        ->and($row->user_agent)->toContain('Chrome/126');
    expect(json_encode([DB::table('audit_log')->get(), DB::table('audit_log_changes')->get()]))->not->toContain('203.0.113.9');
});

test('a refused sign-in leaves no audit row (login_attempts keeps those)', function () {
    Mail::fake();
    $this->withoutDefer();
    $signIn = app(AdminSignIn::class);
    $signIn->begin('admin@raqam.ps', 'wrong horse battery');
    $signIn->verify('123456');

    expect(auditRows())->toHaveCount(0);
});

// ---- the four reasons ------------------------------------------------------------------------

test('signing out by hand is logged as manual', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();

    $this->post($this->url.'/logout')->assertRedirect();

    $this->assertGuest();
    expect(auditRows()->pluck('action')->all())->toBe(['logout'])->and(logoutReasons())->toBe(['manual']);
    expect(auditRows()->sole()->actor_user_id)->toBe($this->admin->id);
});

test('30 quiet minutes are logged as idle', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    $this->travel(31)->minutes();

    $this->actingAs($this->admin)->get($this->url)->assertRedirect();

    expect(logoutReasons())->toBe(['idle']);
});

test('12 hours are logged as absolute even when the admin was active', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    foreach (range(1, 36) as $i) {
        $this->travel(20)->minutes();
        $this->actingAs($this->admin)->get($this->url);
    }

    expect(logoutReasons())->toBe(['absolute']);
});

test('a changed password ends the other sessions, logged as password_changed', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();           // this session remembers the password it began with
    DB::table('users')->where('id', $this->admin->id)->update(['password_hash' => Hash::make('a brand new password')]);

    $this->actingAs($this->admin->fresh())->get($this->url);              // the old session meets the new password

    expect(logoutReasons())->toBe(['password_changed']);
});

test('each sign-out is logged once', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    $this->travel(31)->minutes();

    $this->actingAs($this->admin)->get($this->url);
    $this->get($this->url);
    $this->get($this->url);

    expect(logoutReasons())->toHaveCount(1);
});

test('the reason is a row of audit_log_changes, attached to the logout it explains', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    $this->post($this->url.'/logout');

    $change = DB::table('audit_log_changes')->sole();
    expect($change->audit_id)->toBe(auditRows()->sole()->id)->and($change->old_value)->toBeNull();
});

test('a failing audit write never stops the sign-out', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    config(['raqam.tracking_key' => null]);                               // the audit row cannot be written
    $this->travel(31)->minutes();

    $this->actingAs($this->admin)->get($this->url)->assertRedirect(Filament::getLoginUrl().'?expired=1');

    $this->assertGuest();
    expect(auditRows())->toHaveCount(0);
});

test('the log cannot be edited or deleted', function () {
    signInWithCode();

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->update(['action' => 'update'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->delete()))->toThrow(QueryException::class);
});

test('a customer account is never written to the admin log', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer);
    auth()->logout();

    expect(auditRows())->toHaveCount(0);
});
