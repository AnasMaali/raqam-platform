<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 | Every route is documented (phase 10b freeze): the public API in docs/api/openapi.yaml (ContentApiTest checks it path by
 | path), the panel by its screens (all behind the sign-in, PanelAccessEverywhereTest), and the rest in
 | docs/api/internal-routes.md.
 */

/** Filament's own download routes outside the panel, for features the platform does not use (no such tables). */
const FILAMENT_UNUSED_DOWNLOADS = ['filament.exports.download', 'filament.imports.failed-rows.download'];

function internalRoutesDoc(): array
{
    preg_match_all('/^\| (GET|POST|PUT|PATCH|DELETE) \| `([^`]+)` \|/m', file_get_contents(base_path('../docs/api/internal-routes.md')), $m, PREG_SET_ORDER);

    return array_map(fn ($row) => $row[1].' '.$row[2], $m);
}

test('every route outside the API and the panel is in internal-routes.md, and the list names only routes that exist', function () {
    $routes = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1') || str_starts_with((string) $route->getName(), 'filament.')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = $method.' '.$route->uri();
        }
    }
    sort($routes);
    $documented = internalRoutesDoc();
    sort($documented);

    expect($routes)->toBe($documented);
});

test('every panel route sits under the private panel address', function () {
    $prefix = trim((string) config('raqam.admin_path'), '/');
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'filament.') && ! in_array($route->getName(), FILAMENT_UNUSED_DOWNLOADS, true)) {
            expect($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'))->toBeTrue($route->getName());
        }
    }
});

test('the download routes Filament adds outside the panel are a plain 404 for everybody, never a server error', function () {
    foreach (['/filament/exports/1/download', '/filament/imports/1/failed-rows/download', '/filament/exports/abc/download'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->actingAs(User::factory()->staff()->create());
    $this->get('/filament/exports/1/download')->assertNotFound();
})->uses(RefreshDatabase::class);
