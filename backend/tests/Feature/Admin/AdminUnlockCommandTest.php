<?php

use App\Auth\AdminSignIn;
use App\Mail\AdminLoginCode as CodeMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->withoutDefer();
    $this->admin = User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);
});

function tryFrom(string $ip, string $password = 'wrong horse battery'): ?int
{
    request()->server->set('REMOTE_ADDR', $ip);

    return app(AdminSignIn::class)->begin('admin@raqam.ps', $password);
}

/** Locks the e-mail out: 20 attempts in the hour, each from another address. */
function lockOut(): void
{
    foreach (range(1, 20) as $i) {
        tryFrom("198.51.100.{$i}");
    }
    expect(tryFrom('198.51.100.99', 'correct horse battery'))->toBeGreaterThan(0);
}

test('it lifts a lock-out of the e-mail', function () {
    lockOut();

    $this->artisan('admin:unlock', ['email' => 'admin@raqam.ps'])->expectsOutputToContain('cleared')->assertSuccessful();

    expect(tryFrom('198.51.100.99', 'correct horse battery'))->toBeNull();
    Mail::assertSent(CodeMail::class, 1);
});

test('it lifts the code limits too: the wait and the 3-an-hour cap', function () {
    $signIn = app(AdminSignIn::class);
    request()->server->set('REMOTE_ADDR', '203.0.113.9');
    $signIn->begin('admin@raqam.ps', 'correct horse battery');
    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('sent');
    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('sent');
    $this->travel(61)->seconds();
    expect($signIn->resend())->toBe('limit');

    $this->artisan('admin:unlock', ['email' => 'admin@raqam.ps'])->assertSuccessful();

    expect($signIn->resend())->toBe('sent');
});

test('the e-mail is matched in any letter case and with stray spaces', function () {
    lockOut();

    $this->artisan('admin:unlock', ['email' => '  Admin@RAQAM.ps '])->assertSuccessful();

    expect(tryFrom('198.51.100.99', 'correct horse battery'))->toBeNull();
});

test('it does not lift the limit of an IP address, which is not about the e-mail', function () {
    foreach (range(1, 30) as $i) {
        request()->server->set('REMOTE_ADDR', '203.0.113.50');
        app(AdminSignIn::class)->begin("someone{$i}@raqam.ps", 'whatever password');
    }

    $this->artisan('admin:unlock', ['email' => 'admin@raqam.ps'])->assertSuccessful();

    expect(tryFrom('203.0.113.50', 'correct horse battery'))->toBeGreaterThan(0);
});

test('it is written to the audit log with the account it concerned', function () {
    $this->artisan('admin:unlock', ['email' => 'admin@raqam.ps'])->assertSuccessful();

    $row = DB::table('audit_log')->sole();
    expect($row->action)->toBe('update')
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->subject_table)->toBe('users')->and($row->subject_id)->toBe($this->admin->id)
        ->and($row->ip_hash)->toBeNull();
    expect(DB::table('audit_log_changes')->where('audit_id', $row->id)->pluck('new_value', 'field')->all())->toBe(['sign_in_limits' => 'cleared']);
});

test('an unknown e-mail is cleared too and logged without the address itself', function () {
    app(AdminSignIn::class)->begin('ghost@raqam.ps', 'whatever password');

    $this->artisan('admin:unlock', ['email' => 'ghost@raqam.ps'])->expectsOutputToContain('no such account')->assertSuccessful();

    $row = DB::table('audit_log')->sole();
    expect($row->subject_id)->toBeNull()->and($row->subject_table)->toBeNull();
    expect(DB::table('audit_log_changes')->where('audit_id', $row->id)->orderBy('field')->pluck('new_value', 'field')->all())
        ->toBe(['email_sha256' => hash('sha256', 'ghost@raqam.ps'), 'sign_in_limits' => 'cleared']);
    expect(json_encode(DB::table('audit_log_changes')->get()))->not->toContain('ghost@raqam.ps');
});
