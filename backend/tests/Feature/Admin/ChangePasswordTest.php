<?php

use App\Auth\AdminPasswordChange;
use App\Auth\AdminSessionLimits;
use App\Filament\Pages\ChangePassword;
use App\Mail\PasswordChanged;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const CURRENT = 'correct horse battery';
const NEWER = 'a different long passphrase';
const CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36';

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    fakePwned();
    $this->url = '/'.config('raqam.admin_path');
    $this->admin = User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => CURRENT]);
    $this->actingAs($this->admin);
});

function submitChange(string $current = CURRENT, string $new = NEWER, ?string $confirm = null)
{
    return Livewire::withHeaders(['User-Agent' => CHROME_ON_WINDOWS])->test(ChangePassword::class)
        ->set('data.current_password', $current)
        ->set('data.password', $new)
        ->set('data.password_confirmation', $confirm ?? $new)
        ->call('save');
}

function passwordIs(string $plain): bool
{
    return Hash::check($plain, DB::table('users')->where('email', 'admin@raqam.ps')->value('password_hash'));
}

// ---- the page ---------------------------------------------------------------------------------

test('the page is for a signed-in admin only', function () {
    auth()->logout();
    $this->get($this->url.'/password')->assertRedirect(Filament::getLoginUrl());

    $this->actingAs(User::factory()->create())->get($this->url.'/password')->assertForbidden();
    $this->actingAs($this->admin)->get($this->url.'/password')->assertOk()->assertSee('تغيير كلمة المرور');
});

test('the user menu leads to it', function () {
    $html = $this->actingAs($this->admin)->followingRedirects()->get($this->url)->getContent();

    expect($html)->toContain($this->url.'/password');
});

// ---- what is accepted -------------------------------------------------------------------------

test('a right current password and a good new one change the password', function () {
    submitChange()->assertHasNoErrors()->assertNotified();

    expect(passwordIs(NEWER))->toBeTrue()->and(passwordIs(CURRENT))->toBeFalse();
    expect(password_get_info(DB::table('users')->value('password_hash'))['algoName'])->toBe('argon2id');
});

test('each rule is enforced and nothing changes when one fails', function (string $current, string $new, ?string $confirm, string $field) {
    submitChange($current, $new, $confirm)->assertHasErrors(["data.{$field}"]);

    expect(passwordIs(CURRENT))->toBeTrue();
    Mail::assertNothingSent();
})->with([
    'wrong current password' => ['not my password', NEWER, null, 'current_password'],
    'empty current password' => ['', NEWER, null, 'current_password'],
    'new password too short (9)' => [CURRENT, 'too short', 'too short', 'password'],
    'new password too long' => [CURRENT, str_repeat('a', 129), str_repeat('a', 129), 'password'],
    'new password the same as the current' => [CURRENT, CURRENT, null, 'password'],
    'confirmation differs' => [CURRENT, NEWER, 'something else entirely', 'password'],
    'confirmation empty' => [CURRENT, NEWER, '', 'password_confirmation'],
]);

test('10 and 128 characters are accepted', function (string $new) {
    submitChange(CURRENT, $new)->assertHasNoErrors();

    expect(passwordIs($new))->toBeTrue();
})->with(['10' => ['abcdefghij'], '128' => [str_repeat('ab', 64)]]);

test('a leaked password is refused, and so is any password when the leak service is down', function () {
    fakePwned([NEWER]);
    submitChange()->assertHasErrors(['data.password']);

    fakePwned(reachable: false);
    submitChange()->assertHasErrors(['data.password']);

    expect(passwordIs(CURRENT))->toBeTrue();
});

// ---- the limit ---------------------------------------------------------------------------------

test('the current password can be tried 5 times a minute, wrong or right', function () {
    foreach (range(1, 5) as $i) {
        submitChange('wrong password!')->assertHasErrors(['data.current_password']);
    }

    submitChange()->assertNotified();                       // the 6th is refused although everything is right
    expect(passwordIs(CURRENT))->toBeTrue();

    $this->travel(61)->seconds();
    submitChange()->assertHasNoErrors();
    expect(passwordIs(NEWER))->toBeTrue();
});

test('invalid submissions count too, so the limit cannot be probed around', function () {
    foreach (range(1, 5) as $i) {
        submitChange(CURRENT, 'short')->assertHasErrors(['data.password']);
    }

    submitChange();

    expect(passwordIs(CURRENT))->toBeTrue();
});

// ---- after the change ------------------------------------------------------------------------

/**
 * The change as a real request, so the session is saved and read back like in a browser. (A Livewire test
 * does not keep its session between steps.) The route carries the panel's own middleware and reports what
 * the change itself did to the session, before the middleware's own bookkeeping at the end of the request.
 *
 * @return array{id_before: string, id_after: string, hash_before: ?string, hash_after: ?string}
 */
function changeThroughARequest(string $new = NEWER, array $headers = []): array
{
    $panel = Filament::getPanel('admin');
    Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
        ->post('/__change-password', function () {
            $before = ['id_before' => session()->getId(), 'hash_before' => session('password_hash_web')];
            app(AdminPasswordChange::class)->change(Filament::auth()->user(), request('new'));

            return response()->json($before + ['id_after' => session()->getId(), 'hash_after' => session('password_hash_web')]);
        });

    return test()->withHeaders($headers)->actingAs(test()->admin->fresh())->post('/__change-password', ['new' => $new])->assertOk()->json();
}

test('this session goes on: the change gives it a new id and its own copy of the new password hash', function () {
    $this->get($this->url)->assertOk();                      // the session learns the old hash

    $seen = changeThroughARequest();

    expect($seen['id_after'])->not->toBe($seen['id_before'])
        ->and($seen['hash_before'])->not->toBeNull()
        ->and($seen['hash_after'])->not->toBe($seen['hash_before']);
    $this->actingAs($this->admin->fresh())->get($this->url)->assertOk();     // not signed out by its own change
    $this->assertAuthenticated();
});

test('every other session ends, with the reason password_changed', function () {
    // Session A: another browser, signed in with the old password.
    $this->get($this->url)->assertOk();
    $sessionA = session()->all();

    // Session B: the one that changes the password.
    $this->flushSession();
    $this->actingAs($this->admin->fresh())->get($this->url)->assertOk();
    changeThroughARequest();
    $sessionB = session()->all();

    // Session B carries on.
    $this->flushSession();
    $this->withSession($sessionB)->actingAs($this->admin->fresh())->get($this->url)->assertOk();

    // Session A meets the new password and is ended.
    $this->flushSession();
    $this->withSession($sessionA)->actingAs($this->admin->fresh())->get($this->url)->assertRedirect(Filament::getLoginUrl());
    $this->assertGuest();

    expect(DB::table('audit_log_changes')->where('field', 'logout_reason')->pluck('new_value')->all())->toBe(['password_changed']);
});

test('the e-mail is sent after the response, not during it', function () {
    $this->withDefer();

    submitChange();

    Mail::assertNotSent(PasswordChanged::class);
    app(DeferredCallbackCollection::class)->invoke();
    Mail::assertSent(PasswordChanged::class, 1);
});

test('the e-mail carries the time, the browser, the system and the warning, and no password', function () {
    changeThroughARequest(headers: ['User-Agent' => CHROME_ON_WINDOWS]);

    Mail::assertSent(PasswordChanged::class, function (PasswordChanged $mail) {
        $html = $mail->render();

        return $mail->hasTo('admin@raqam.ps')
            && str_contains($html, 'Chrome') && str_contains($html, 'Windows') && str_contains($html, $mail->time)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $mail->time)
            && str_contains($html, 'تم تغيير كلمة المرور')
            && str_contains($html, 'فتواصل مع الدعم الفني فوراً')
            && ! str_contains($html, NEWER) && ! str_contains($html, 'Mozilla');
    });
});

test('no e-mail when nothing changed', function () {
    $this->withDefer();

    submitChange('wrong password!');
    app(DeferredCallbackCollection::class)->invoke();

    Mail::assertNothingSent();
});

test('the change is logged without any password', function () {
    submitChange();

    $row = DB::table('audit_log')->where('action', 'update')->sole();
    expect($row->actor_user_id)->toBe($this->admin->id)->and($row->subject_id)->toBe($this->admin->id);
    expect(DB::table('audit_log_changes')->where('audit_id', $row->id)->pluck('new_value', 'field')->all())->toBe(['password' => 'changed']);
    expect(json_encode([DB::table('audit_log')->get(), DB::table('audit_log_changes')->get()]))->not->toContain(NEWER)->not->toContain(CURRENT);
});

test('changing the password does not restart the 12-hour clock', function () {
    app(AdminSessionLimits::class)->start();
    $this->travel(5)->hours();
    $before = app(AdminSessionLimits::class)->absoluteRemaining();

    submitChange();

    expect(app(AdminSessionLimits::class)->absoluteRemaining())->toBe($before);
});
