<?php

use App\Auth\AdminSignIn;
use App\Auth\KnownDevices;
use App\Mail\AdminLoginCode as CodeMail;
use App\Mail\NewDeviceSignIn;
use App\Models\User;
use App\Support\TrackingHash;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->withoutDefer();
    request()->headers->set('User-Agent', CHROME);
    $this->admin = User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);
});

/** Both steps of the sign-in, from a browser that sends $cookie (or none). */
function completeSignIn(?string $cookie = null, string $email = 'admin@raqam.ps'): void
{
    request()->cookies->remove(KnownDevices::COOKIE);
    $cookie && request()->cookies->set(KnownDevices::COOKIE, $cookie);

    $signIn = app(AdminSignIn::class);
    $signIn->begin($email, 'correct horse battery');
    $signIn->verify(Mail::sent(CodeMail::class)->last()->code);
    auth()->logout();
    RateLimiterReset();
}

function RateLimiterReset(): void
{
    RateLimiter::clear('admin-code:cooldown:'.hash('sha256', 'admin@raqam.ps'));
    RateLimiter::clear('admin-code:hourly:'.hash('sha256', 'admin@raqam.ps'));
}

function deviceCookie(): ?Symfony\Component\HttpFoundation\Cookie
{
    return collect(Cookie::getQueuedCookies())->first(fn ($c) => $c->getName() === KnownDevices::COOKIE);
}

test('no device cookie is given after the password, nor after a wrong code', function () {
    $signIn = app(AdminSignIn::class);
    $signIn->begin('admin@raqam.ps', 'correct horse battery');
    expect(deviceCookie())->toBeNull();

    $signIn->verify('000000');
    expect(deviceCookie())->toBeNull()->and(DB::table('user_devices')->count())->toBe(0);
});

test('a device cookie is given after the code, with the strict attributes and a year of life', function () {
    completeSignIn();

    $cookie = deviceCookie();
    expect($cookie)->not->toBeNull()
        ->and($cookie->getName())->toStartWith('__Host-')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getDomain())->toBeNull()
        ->and(strlen($cookie->getValue()))->toBeGreaterThanOrEqual(64)
        ->and($cookie->getExpiresTime())->toBeBetween(now()->addDays(364)->getTimestamp(), now()->addDays(366)->getTimestamp());
});

test('the table keeps the HMAC of the cookie and never the cookie', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();

    $row = DB::table('user_devices')->first();
    expect($row->device_hash)->toBe(hash_hmac('sha256', $value, config('raqam.tracking_key')))
        ->and($row->user_id)->toBe($this->admin->id)
        ->and($row->user_agent)->toBe(CHROME)
        ->and(json_encode(DB::table('user_devices')->get()))->not->toContain($value)
        ->and(json_encode(DB::table('login_attempts')->get()))->not->toContain($value);
});

test('a new device triggers an alert after the response, with time, browser, system and the warning', function () {
    $this->withDefer();
    $signIn = app(AdminSignIn::class);
    $signIn->begin('admin@raqam.ps', 'correct horse battery');
    app(DeferredCallbackCollection::class)->invoke();   // the code mail
    $signIn->verify(Mail::sent(CodeMail::class)->last()->code);

    Mail::assertNotSent(NewDeviceSignIn::class);                                  // not during the response
    app(DeferredCallbackCollection::class)->invoke();

    Mail::assertSent(NewDeviceSignIn::class, function (NewDeviceSignIn $mail) {
        $html = $mail->render();

        return $mail->hasTo('admin@raqam.ps')
            && str_contains($html, 'Chrome') && str_contains($html, 'Windows')
            && str_contains($html, $mail->time) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $mail->time)
            && str_contains($html, 'إذا لم تكن أنت فغيّر كلمة المرور فوراً')
            && ! str_contains($html, 'Mozilla');                                  // no raw User-Agent
    });
});

test('the same browser is recognised: no alert, the cookie is renewed', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    Mail::fake();
    $this->travel(2)->days();

    completeSignIn($value);

    Mail::assertNotSent(NewDeviceSignIn::class);
    expect(DB::table('user_devices')->count())->toBe(1)
        ->and(deviceCookie()->getValue())->toBe($value);
});

test('a clock stepped back since the device was first seen does not break its next sign-in', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    DB::table('user_devices')->update(['first_seen_at' => DB::raw("now() + interval '2 seconds'"), 'last_seen_at' => DB::raw("now() + interval '2 seconds'")]);
    Mail::fake();

    completeSignIn($value);

    Mail::assertNotSent(NewDeviceSignIn::class);
    $row = DB::table('user_devices')->first();
    expect($row->last_seen_at)->toBe($row->first_seen_at)
        ->and(DB::table('login_attempts')->where('succeeded', true)->count())->toBe(2);
});

test('a cookie nobody issued, or garbage, counts as a new device', function (string $cookie) {
    completeSignIn($cookie);

    Mail::assertSent(NewDeviceSignIn::class, 1);
    expect(DB::table('user_devices')->count())->toBe(1)->and(deviceCookie()->getValue())->not->toBe($cookie);
})->with(['unknown value' => [str_repeat('ab', 32)], 'garbage' => ['x'], 'too long' => [str_repeat('z', 500)]]);

test('a device not seen for six months is forgotten and counts as new when it returns', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    DB::table('user_devices')->update([
        'first_seen_at' => DB::raw("now() - interval '8 months'"), 'last_seen_at' => DB::raw("now() - interval '7 months'"),
    ]);
    Mail::fake();

    completeSignIn($value);

    Mail::assertSent(NewDeviceSignIn::class, 1);
    $rows = DB::table('user_devices')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->device_hash)->not->toBe(TrackingHash::of($value))    // the old one is gone
        ->and(deviceCookie()->getValue())->not->toBe($value);
});

test('a device seen five months ago is still known', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    DB::table('user_devices')->update([
        'first_seen_at' => DB::raw("now() - interval '8 months'"), 'last_seen_at' => DB::raw("now() - interval '5 months'"),
    ]);
    Mail::fake();

    completeSignIn($value);

    Mail::assertNotSent(NewDeviceSignIn::class);
});

test('another account on the same browser is a new device for that account', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    User::factory()->staff()->create(['email' => 'second@raqam.ps', 'password_hash' => 'correct horse battery']);
    Mail::fake();

    $signIn = app(AdminSignIn::class);
    request()->cookies->set(KnownDevices::COOKIE, $value);
    $signIn->begin('second@raqam.ps', 'correct horse battery');
    $signIn->verify(Mail::sent(CodeMail::class)->last()->code);

    Mail::assertSent(NewDeviceSignIn::class, fn ($mail) => $mail->hasTo('second@raqam.ps'));
    expect(DB::table('user_devices')->count())->toBe(2);
});

test('devices:prune removes what has not been seen for six months and keeps the rest', function () {
    completeSignIn();
    DB::table('user_devices')->insert([
        ['user_id' => $this->admin->id, 'device_hash' => str_repeat('a', 64),
            'first_seen_at' => DB::raw("now() - interval '9 months'"), 'last_seen_at' => DB::raw("now() - interval '7 months'")],
        ['user_id' => $this->admin->id, 'device_hash' => str_repeat('b', 64),
            'first_seen_at' => DB::raw("now() - interval '9 months'"), 'last_seen_at' => DB::raw("now() - interval '2 months'")],
    ]);

    $this->artisan('devices:prune')->expectsOutputToContain('Forgot 1 devices')->assertSuccessful();

    expect(DB::table('user_devices')->pluck('device_hash')->all())->not->toContain(str_repeat('a', 64))
        ->toContain(str_repeat('b', 64))->toHaveCount(2);
});

test('the prune runs every day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'devices:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

test('attempts name the device when the browser sent its cookie', function () {
    completeSignIn();
    $value = deviceCookie()->getValue();
    request()->cookies->set(KnownDevices::COOKIE, $value);

    app(AdminSignIn::class)->begin('admin@raqam.ps', 'wrong horse battery');

    expect(DB::table('login_attempts')->latest('id')->value('device_hash'))->toBe(TrackingHash::of($value));
});
