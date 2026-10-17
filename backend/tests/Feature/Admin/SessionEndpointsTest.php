<?php

use App\Auth\AdminSessionLimits;
use App\Http\Controllers\AdminSessionController;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();   // the tests count seconds: a real second ticking over must not change the result
    Filament::setCurrentPanel('admin');
    $this->url = '/'.config('raqam.admin_path');
    $this->admin = User::factory()->staff()->create();
    $this->withSession(['_token' => 'page-token']);
});

function statusCall(?string $token = 'page-token', ?User $as = null)
{
    $test = test()->actingAs($as ?? test()->admin);

    return ($token === null ? $test : $test->withHeader('X-CSRF-TOKEN', $token))->getJson(test()->url.'/session/status');
}

function keepAliveCall(?User $as = null)
{
    return test()->actingAs($as ?? test()->admin)->postJson(test()->url.'/session/keep-alive');
}

// ---- both need a signed-in admin, CSRF and the limit -----------------------------------------

test('both routes sit behind sign-in, CSRF protection and the 10-a-minute limit', function () {
    foreach (['filament.admin.session.keep-alive', 'filament.admin.session.status'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('throttle:admin-session');
        expect(app('router')->resolveMiddleware($middleware, $route->excludedMiddleware()))->toContain(Authenticate::class);
        expect($route->getActionName())->toStartWith(AdminSessionController::class);
    }

    $keepAlive = Route::getRoutes()->getByName('filament.admin.session.keep-alive');
    expect($keepAlive->methods())->toContain('POST')->and($keepAlive->gatherMiddleware())->toContain(VerifyCsrfToken::class);
    expect(Route::getRoutes()->getByName('filament.admin.session.status')->methods())->toContain('GET');
});

test('a guest gets nothing from either', function () {
    $this->getJson($this->url.'/session/status')->assertUnauthorized();
    $this->postJson($this->url.'/session/keep-alive')->assertUnauthorized();
});

test('a customer account gets nothing from either', function () {
    $customer = User::factory()->create();

    statusCall(as: $customer)->assertForbidden();
    keepAliveCall($customer)->assertForbidden();
});

test('the status question needs the CSRF token in the header', function () {
    statusCall(null)->assertStatus(419);
    statusCall('wrong-token')->assertStatus(419);
    statusCall('')->assertStatus(419);
    statusCall('page-token')->assertOk()->assertJson(['remaining' => 1800]);
});

// ---- the status question is not activity -----------------------------------------------------

test('asking does not extend the session: the time left keeps shrinking', function () {
    $limits = app(AdminSessionLimits::class);
    $limits->start();

    $this->travel(20)->minutes();
    statusCall()->assertOk()->assertJson(['remaining' => 600]);

    $this->travel(5)->minutes();
    statusCall()->assertOk()->assertJson(['remaining' => 300]);          // not back to 1800
});

test('a session that only ever asked is over 30 minutes after the last real request', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();          // activity at minute 0

    $this->travel(29)->minutes();
    statusCall()->assertOk();                                            // minute 29: asking does not save it

    $this->travel(2)->minutes();                                         // minute 31
    $this->actingAs($this->admin)->get($this->url)->assertRedirect(Filament::getLoginUrl().'?expired=1');
});

test('the keep-alive, unlike the question, does extend it', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();

    $this->travel(29)->minutes();
    keepAliveCall()->assertOk()->assertJson(['remaining' => 1800]);

    $this->travel(29)->minutes();
    $this->actingAs($this->admin)->get($this->url)->assertOk();
});

test('asking after the end gets 419 and finishes the sign-out', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    $this->travel(31)->minutes();

    statusCall()->assertStatus(419);

    $this->assertGuest();
    expect(DB::table('audit_log_changes')->where('field', 'logout_reason')->value('new_value'))->toBe('idle');
});

test('the status never reports more than the 12-hour limit allows', function () {
    $this->actingAs($this->admin)->get($this->url)->assertOk();
    foreach (range(1, 35) as $i) {
        $this->travel(20)->minutes();
        $this->actingAs($this->admin)->get($this->url)->assertOk();
    }
    $this->travel(15)->minutes();

    $remaining = statusCall()->assertOk()->json('remaining');

    expect($remaining)->toBeLessThanOrEqual(300)->toBeGreaterThan(0);
});

// ---- the limit: 10 a minute, for each route and each admin -----------------------------------

test('the keep-alive is limited to 10 a minute', function () {
    foreach (range(1, 10) as $i) {
        keepAliveCall()->assertOk();
    }

    keepAliveCall()->assertStatus(429);

    $this->travel(61)->seconds();
    keepAliveCall()->assertOk();
});

test('the status question is limited to 10 a minute', function () {
    foreach (range(1, 10) as $i) {
        statusCall()->assertOk();
    }

    statusCall()->assertStatus(429);

    $this->travel(61)->seconds();
    statusCall()->assertOk();
});

test('each route and each admin has its own allowance', function () {
    foreach (range(1, 10) as $i) {
        statusCall()->assertOk();
    }
    statusCall()->assertStatus(429);

    keepAliveCall()->assertOk();                                          // the other route is untouched
    $other = User::factory()->staff()->create();
    statusCall(as: $other)->assertOk();                                   // and so is another admin
});

test('a refused question does not hide a wrong token behind the limit', function () {
    foreach (range(1, 10) as $i) {
        statusCall()->assertOk();
    }

    statusCall(null)->assertStatus(429);                                  // limited first: no free probing either
});
