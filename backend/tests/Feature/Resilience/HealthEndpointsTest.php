<?php

use App\Panel\SystemWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

/*
 * The two health addresses (docs/deployment-requirements.md, "Watching the platform"):
 *   - `/up`: every part of the platform, for the monitor outside the server only. It sends HEALTH_TOKEN in the
 *     X-Health-Token header; anyone else gets the 404 of a page that does not exist, and nothing is looked at.
 *   - `/livez`: PHP answers, nothing more, for the container's own health check. A stopped worker, scheduler, Redis or
 *     database, or the maintenance mode, must not make it fail: the web server would be restarted for nothing.
 */

uses(RefreshDatabase::class);

const HEALTH_TEST_TOKEN = 'a-health-token-for-these-tests-of-40-chars';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.health.token' => HEALTH_TEST_TOKEN]);
    staffAdmin('admin@raqam.example');
    Cache::forget(SystemWatch::BEAT);   // the scheduler never beat: /up, if it looked, would find it stopped
});

/** Redis gone for everything that keeps its data there. */
function healthWithoutRedis(): void
{
    config(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis',
        'database.redis.default.port' => 1, 'database.redis.cache.port' => 1, 'database.redis.session.port' => 1]);
    foreach (['redis', 'cache', 'cache.store', 'queue', 'session', 'session.store'] as $name) {
        app()->forgetInstance($name);
        Facade::clearResolvedInstance($name);
    }
}

function healthAlerts(): int
{
    return DB::table('admin_notifications')->where('dedupe_key', 'like', 'down:%')->count();
}

// ---- /up ----------------------------------------------------------------------------------------------------------

test('/up answers the monitor that sends the token, and only it', function () {
    $this->getJson('/up', ['X-Health-Token' => HEALTH_TEST_TOKEN])->assertStatus(503)->assertJsonPath('checks.scheduler', false);
});

test('without the token, or with a wrong one, /up is the 404 of a missing page and looks at nothing', function (?string $sent) {
    healthWithoutRedis();
    $headers = $sent === null ? [] : ['X-Health-Token' => $sent];

    $up = $this->get('/up', $headers)->assertNotFound();
    expect(healthAlerts())->toBe(0);   // nothing looked at, so nobody told: Redis and the scheduler are both down here

    $missing = $this->get('/no-such-page-anywhere')->assertNotFound();   // (this one does find Redis down)
    expect($up->getContent())->toBe($missing->getContent())
        ->and($up->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'));
})->with([
    'no header' => [null],
    'empty' => [''],
    'wrong' => ['not-the-token-at-all-but-just-as-long-xx'],
    'the start of the token' => [substr(HEALTH_TEST_TOKEN, 0, 20)],
    'the token and more' => [HEALTH_TEST_TOKEN.'x'],
    'another case' => [strtoupper(HEALTH_TEST_TOKEN)],
]);

test('with no HEALTH_TOKEN set, /up answers nobody, not even an empty token', function () {
    config(['raqam.health.token' => null]);
    $this->get('/up')->assertNotFound();
    $this->get('/up', ['X-Health-Token' => ''])->assertNotFound();

    config(['raqam.health.token' => '']);
    $this->get('/up', ['X-Health-Token' => ''])->assertNotFound();
    expect(healthAlerts())->toBe(0);
});

test('the token is read from the header only, never from the address', function () {
    $this->get('/up?token='.HEALTH_TEST_TOKEN)->assertNotFound();
    $this->get('/up?X-Health-Token='.HEALTH_TEST_TOKEN)->assertNotFound();
});

// ---- /livez -------------------------------------------------------------------------------------------------------

test('/livez answers 200 from PHP alone: no database, no Redis, no scheduler, no alert', function () {
    healthWithoutRedis();
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $response = $this->get('/livez')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->getContent())->toBe('ok')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->getCookies())->toBe([])
        ->and($queries)->toBe(0)
        ->and(healthAlerts())->toBe(0);
});

test('/livez needs no token and keeps answering during maintenance (php artisan down), when the site does not', function () {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate([]);

    try {
        $this->get('/livez')->assertOk();
        $this->getJson('/api/v1/site')->assertStatus(503);
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});
