<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The systems built and switched off in V1 (decisions 15.13, 15.14, 16.13, 17.1, 17.13, 17.14, 2.7): as installed they
// are off; while off every one of their routes is a 404 whatever is sent; switched on (in tests only) the routes exist.

uses(RefreshDatabase::class);

const DORMANT_FLAGS = ['booking', 'online_payments', 'customer_accounts', 'multi_admin'];

beforeEach(function () {
    Cache::flush();
    config(['raqam.api.site_token' => 'dormant-site-token']);
});

/** @return list<Route> every API route that belongs to a switched-off system */
function dormantRoutes(): array
{
    return array_values(array_filter(app('router')->getRoutes()->getRoutes(),
        fn (Route $r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'feature:'))));
}

function concretePath(Route $route): string
{
    return '/'.preg_replace(['/\{post\}/', '/\{kind\}/', '/\{id\}/', '/\{gateway\}/', '/\{\w+\}/'], ['1', 'category', '1', 'fake', 'x'], $route->uri());
}

function send(Route $route, array $headers = [])
{
    $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

    return test()->flushHeaders()->withHeaders($headers)->json($method, concretePath($route), ['token' => 'x', 'email' => 'a@example.com']);
}

test('as installed, every dormant system is switched off', function () {
    expect(DB::table('feature_flags')->whereIn('key', DORMANT_FLAGS)->pluck('is_enabled', 'key')->all())
        ->toEqual(array_fill_keys(DORMANT_FLAGS, false));
});

test('every route of booking, payment and accounts sits behind its switch, and nothing else does', function () {
    $prefixes = ['api/v1/bookings', 'api/v1/payments', 'api/v1/account'];
    $guarded = array_map(fn (Route $r) => $r->uri(), dormantRoutes());
    $expected = collect(app('router')->getRoutes()->getRoutes())->map(fn (Route $r) => $r->uri())
        ->filter(fn ($uri) => collect($prefixes)->contains(fn ($p) => $uri === $p || str_starts_with($uri, $p.'/')))->unique()->values()->all();

    expect(array_values(array_unique($guarded)))->toEqualCanonicalizing($expected)->and(count($expected))->toBe(20);
});

test('while off, every dormant route is a plain 404, with or without the site\'s token, past any request limit', function () {
    $site = ['X-Raqam-Site-Token' => 'dormant-site-token', 'X-Raqam-Visitor-Ip' => '203.0.113.7', 'Authorization' => 'Bearer '.str_repeat('a', 43)];
    foreach (dormantRoutes() as $route) {
        foreach ([[], $site] as $headers) {
            send($route, $headers)->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'غير موجود.']]);
        }
    }
    foreach (range(1, 35) as $_) {   // above every one of their request limits
        send(dormantRoutes()[0], $site)->assertNotFound();
    }
    expect(DB::table('bookings')->count() + DB::table('payments')->count() + DB::table('payment_events')->count()
        + DB::table('users')->where('user_type', 'customer')->count())->toBe(0);
});

test('switched on (in tests only), no dormant route is a 404 any more for want of its switch', function () {
    DB::table('feature_flags')->whereIn('key', ['booking', 'customer_accounts'])->update(['is_enabled' => true]);
    DB::table('feature_flags')->where('key', 'online_payments')->update(['is_enabled' => true]);
    $site = ['X-Raqam-Site-Token' => 'dormant-site-token', 'X-Raqam-Visitor-Ip' => '203.0.113.7'];
    // Past the switch every route answers for what was sent (403, 401, 415, 422...). Three answer a 404 of their own
    // for the made-up token and order sent here, so they are checked by their refusal of a missing site token instead.
    $ownNotFound = ['api/v1/bookings/track', 'api/v1/bookings/pay', 'api/v1/payments/return'];
    foreach (dormantRoutes() as $route) {
        if (in_array($route->uri(), $ownNotFound, true)) {
            send($route, [])->assertStatus(403);

            continue;
        }
        expect(send($route, $site)->status())->not->toBe(404, $route->uri());
    }
});

test('the panel has no screen for bookings, payments, invoices, customers or roles', function () {
    $resources = collect(Filament::getPanel('admin')->getResources())->map(fn ($r) => class_basename($r))->implode(' ');
    expect($resources)->not->toMatch('/Booking|Payment|Invoice|Refund|Customer|Role|Permission/');
    $pages = collect(Filament::getPanel('admin')->getPages())->map(fn ($p) => class_basename($p))->implode(' ');
    expect($pages)->not->toMatch('/Booking|Payment|Invoice|Customer|Role/');
});

test('the services API offers no booking button while booking is off', function () {
    insertService(['slug' => 'dormant-service']);
    test()->getJson('/api/v1/services')->assertOk()->assertJsonPath('meta.booking.enabled', false);
    test()->getJson('/api/v1/services/dormant-service')->assertOk()->assertJsonPath('data.booking.enabled', false)->assertJsonPath('data.booking.path', null);
});
