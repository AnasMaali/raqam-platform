<?php

use App\Auth\AdminSessionLimits;
use App\Filament\Pages\Auth\AdminLogin;
use App\Http\Middleware\EnforceAdminSessionLimits;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->url = '/'.config('raqam.admin_path');
    $this->admin = User::factory()->staff()->create();
});

function panelGet(string $path = '')
{
    return test()->actingAs(test()->admin)->get(test()->url.$path);
}

test('activity inside the idle limit keeps the session; 31 quiet minutes end it', function () {
    panelGet()->assertOk();                                    // starts the clocks

    $this->travel(29)->minutes();
    panelGet()->assertOk();                                    // 29 quiet minutes: still in

    $this->travel(29)->minutes();                              // 29 after the last activity: still in
    panelGet()->assertOk();

    $this->travel(31)->minutes();
    panelGet()->assertRedirect(Filament::getLoginUrl().'?expired=1');
    $this->assertGuest();
});

test('the idle limit is 30 minutes to the second', function (int $quietSeconds, bool $stillIn) {
    panelGet()->assertOk();
    $this->travel($quietSeconds)->seconds();

    $stillIn ? panelGet()->assertOk() : panelGet()->assertRedirect(Filament::getLoginUrl().'?expired=1');
})->with([[1799, true], [1801, false]]);

test('twelve hours end the session even for someone who never stops working', function () {
    panelGet()->assertOk();

    foreach (range(1, 35) as $i) {                              // a request every 20 minutes for 11 h 40 min
        $this->travel(20)->minutes();
        panelGet()->assertOk();
    }

    $this->travel(21)->minutes();                               // 12 h 01 min after sign-in, 21 min after the last request
    panelGet()->assertRedirect(Filament::getLoginUrl().'?expired=1');
    $this->assertGuest();
});

test('the session is invalidated, not just logged out', function () {
    panelGet()->assertOk();
    $token = session()->token();
    $this->travel(31)->minutes();

    panelGet();

    expect(session()->token())->not->toBe($token);
});

test('a Livewire request after the end gets 419, which the page turns into the redirect', function () {
    panelGet()->assertOk();
    $this->travel(31)->minutes();

    $this->actingAs($this->admin)->withHeader('X-Livewire', 'true')->get($this->url)->assertStatus(419);
    $this->assertGuest();
});

test('the middleware also guards Livewire requests', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(EnforceAdminSessionLimits::class);
});

test('the keep-alive request is real: it restarts the idle clock and reports the time left', function () {
    panelGet()->assertOk();
    $this->travel(29)->minutes();

    $this->actingAs($this->admin)->postJson($this->url.'/session/keep-alive')
        ->assertOk()->assertJson(['remaining' => 1800]);

    $this->travel(29)->minutes();                               // 58 minutes after sign-in, 29 after the keep-alive
    panelGet()->assertOk();
});

test('the keep-alive cannot outlive the twelve hours, and a guest cannot use it', function () {
    panelGet()->assertOk();
    foreach (range(1, 35) as $i) {                              // keep working up to 11 h 40 min
        $this->travel(20)->minutes();
        panelGet()->assertOk();
    }
    $this->travel(19)->minutes();
    $this->actingAs($this->admin)->postJson($this->url.'/session/keep-alive')->assertOk();

    expect(app(AdminSessionLimits::class)->remaining())->toBeLessThanOrEqual(60);   // not 1800: the 12 h cap wins

    $this->travel(2)->minutes();
    $this->actingAs($this->admin)->postJson($this->url.'/session/keep-alive')->assertStatus(419);

    auth()->logout();
    $this->postJson($this->url.'/session/keep-alive')->assertUnauthorized();
});

test('the time left counts down from the last request', function () {
    app(AdminSessionLimits::class)->start();
    expect(app(AdminSessionLimits::class)->remaining())->toBe(1800);

    $this->travel(28)->minutes();
    expect(app(AdminSessionLimits::class)->remaining())->toBe(120);

    $this->travel(5)->minutes();
    expect(app(AdminSessionLimits::class)->remaining())->toBe(0)
        ->and(app(AdminSessionLimits::class)->status())->toBe('idle');
});

test('the sign-in page says the session ended', function () {
    Livewire::test(AdminLogin::class)->assertNotNotified();

    Livewire::withQueryParams(['expired' => 1])->test(AdminLogin::class)->assertNotified('انتهت الجلسة، سجّل الدخول من جديد.');
});

// ---- the warning dialog -------------------------------------------------------------------------

test('the dashboard carries the warning dialog with the real keep-alive address', function () {
    $html = $this->actingAs($this->admin)->followingRedirects()->get($this->url)->assertOk()->getContent();

    expect($html)->toContain('role="alertdialog"')
        ->toContain('أنا هون')
        ->toContain('ستنتهي جلستك قريباً')
        ->toContain(str_replace('/', '\\/', $this->url.'/session/keep-alive'))   // as the page's script holds it (JSON)
        ->toContain('const response = await fetch');
});

test('the sign-in pages do not carry it', function () {
    $this->get($this->url.'/login')->assertOk()->assertDontSee('alertdialog', false);
});

test('nothing polls the server, because polling would keep the session alive by itself', function () {
    $html = $this->actingAs($this->admin)->followingRedirects()->get($this->url)->getContent();

    expect($html)->not->toContain('wire:poll')->not->toContain('data-poll');
});

test('the page script makes exactly two requests, the keep-alive and the status question, and has no timer that could poll', function () {
    $view = file_get_contents(resource_path('views/filament/session-timeout.blade.php'));

    expect(substr_count($view, 'fetch('))->toBe(2)
        ->and($view)->not->toContain('setTimeout')
        ->and(substr_count($view, 'setInterval'))->toBe(1);          // the display tick
});
