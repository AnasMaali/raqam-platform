<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The attacker's view: walk every route the panel registers (not the ones we remembered to test) and ask for it
 * as nobody, and as a customer account. Only the two sign-in pages may answer a guest.
 */
function panelRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => str_starts_with((string) $route->getName(), 'filament.admin.'))
        ->values()
        ->all();
}

function concreteUri(LaravelRoute $route): string
{
    return '/'.preg_replace('/\{[^}]+\??\}/', '1', $route->uri());
}

const OPEN_TO_GUESTS = ['filament.admin.auth.login', 'filament.admin.auth.login.code'];

beforeEach(fn () => Filament::setCurrentPanel('admin'));

test('the panel registers routes at all (so the walk below means something)', function () {
    expect(count(panelRoutes()))->toBeGreaterThan(25);
});

test('a guest gets nothing from any panel page or action', function () {
    foreach (panelRoutes() as $route) {
        if (in_array($route->getName(), OPEN_TO_GUESTS, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->call($method, concreteUri($route));

            expect($response->status())->toBeIn([302, 401, 403, 404, 405, 419], "{$method} {$route->uri()} answered a guest with {$response->status()}");
            if ($response->isRedirection()) {
                expect($response->headers->get('Location'))->toContain('/login');
            }
        }
    }
});

test('a customer account gets nothing from any panel page', function () {
    $customer = User::factory()->create();

    foreach (panelRoutes() as $route) {
        if (in_array($route->getName(), OPEN_TO_GUESTS, true) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $status = $this->actingAs($customer)->get(concreteUri($route))->status();

        expect($status)->toBeIn([302, 403, 404], "GET {$route->uri()} answered a customer with {$status}");
    }
});

test('an inactive staff account gets nothing either', function () {
    $staff = User::factory()->staff()->create(['is_active' => false]);

    foreach (panelRoutes() as $route) {
        if (in_array($route->getName(), OPEN_TO_GUESTS, true) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $status = $this->actingAs($staff)->get(concreteUri($route))->status();

        expect($status)->toBeIn([302, 403, 404], "GET {$route->uri()} answered an inactive account with {$status}");
    }
});

test('odd record keys in the address are a 404, never a server error', function (string $key) {
    $admin = User::factory()->staff()->create();

    foreach (panelRoutes() as $route) {
        if (! str_contains($route->uri(), '{record}') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = '/'.str_replace('{record}', rawurlencode($key), $route->uri());
        $status = $this->actingAs($admin)->get($uri)->status();

        expect($status)->toBeLessThan(500, "GET {$route->uri()} with record [{$key}] gave {$status}");
    }
})->with(['letters' => 'abc', 'huge' => '99999999999999999999999', 'negative' => '-1', 'zero' => '0', 'sql' => "1' OR '1'='1", 'array' => '1%5B%5D', 'long' => str_repeat('9', 300), 'unicode' => 'ثلاثة']);
