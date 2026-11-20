<?php

use App\Jobs\SendSystemEmail;
use App\Mail\TemplatedEmail;
use App\Panel\SystemWatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/*
 * The platform's own parts stopping (QA-6, docs/qa/resilience.md): the queue worker, the scheduler, the search engine.
 * Each is noticed, the admins are told once (bell and an e-mail sent right away, never queued), `/up` says which part
 * is failing for a monitor outside, and everything is back to normal when the part runs again.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    // Deferred work runs after the answer as on the server (not at once): an answer of 503 must still raise the alert.
    config(['mail.default' => 'array']);
    $this->admin = staffAdmin('admin@raqam.example');
    Cache::forget(SystemWatch::BEAT);
    config(['raqam.health.token' => MONITOR_TOKEN]);
});

/** The outside monitor's token (HEALTH_TOKEN); `/up` is a 404 for anyone without it (HealthEndpointsTest). */
const MONITOR_TOKEN = 'a-monitor-token-of-at-least-thirty-two-chars';

/** @return array<string, string> */
function monitorHeader(): array
{
    return ['X-Health-Token' => MONITOR_TOKEN];
}

/** A queue of this test only, in the test database of Redis, as production keeps its jobs. */
function watchedQueue(): string
{
    $name = 'qa6-'.bin2hex(random_bytes(4));
    config(['queue.default' => 'redis', 'queue.connections.redis.queue' => $name]);
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');

    return $name;
}

function forgetWatchedQueue(string $name): void
{
    Redis::connection()->del(['queues:'.$name, 'queues:'.$name.':notify']);
}

function downAlerts(string $part): Collection
{
    return DB::table('admin_notifications')->where('dedupe_key', 'down:'.$part)->get();
}

// ---- the scheduler ------------------------------------------------------------------------------------------------

test('the scheduler beats every minute, and /up is happy while it does', function () {
    $this->artisan('system:watch')->assertSuccessful();

    expect(Cache::get(SystemWatch::BEAT))->toBe(now()->getTimestamp());
    $this->getJson('/up', monitorHeader())->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['database' => true, 'redis' => true, 'scheduler' => true, 'queue' => true]]);
    expect(downAlerts('scheduler'))->toHaveCount(0);
});

test('a scheduler that stopped is a 503 of /up and one alert, e-mailed right away', function () {
    $this->freezeTime();
    SystemWatch::beat();
    $this->travel(6)->minutes();

    $this->getJson('/up', monitorHeader())->assertStatus(503)->assertJsonPath('status', 'failing')->assertJsonPath('checks.scheduler', false)->assertJsonPath('checks.queue', true);
    $this->getJson('/up', monitorHeader())->assertStatus(503);

    $alerts = downAlerts('scheduler');
    expect($alerts)->toHaveCount(1)->and($alerts[0]->title)->toBe('المهام المجدولة متوقفة منذ 6 دقيقة')
        ->and(DB::table('email_log')->where('template_key', 'admin_service_down')->pluck('status')->all())->toBe(['sent']);

    // Running again: the next beat makes everything right.
    $this->artisan('system:watch')->assertSuccessful();
    $this->getJson('/up', monitorHeader())->assertOk();
});

test('a scheduler that never ran is noticed too', function () {
    $this->getJson('/up', monitorHeader())->assertStatus(503)->assertJsonPath('checks.scheduler', false);

    expect(downAlerts('scheduler')->pluck('title')->all())->toBe(['المهام المجدولة لا تعمل']);
});

test('four minutes without a beat is still a scheduler at work', function () {
    $this->freezeTime();
    SystemWatch::beat();
    $this->travel(4)->minutes();

    expect(SystemWatch::schedulerLate())->toBeNull();
    $this->travel(1)->minutes();
    expect(SystemWatch::schedulerLate())->toBe(5);
});

test('the queue worker looks at the scheduler every few minutes, not at once when it starts', function () {
    $this->freezeTime();
    SystemWatch::beat();
    $this->travel(20)->minutes();
    $looked = new ReflectionProperty(SystemWatch::class, 'workerLooked');
    $looked->setValue(null, null);

    SystemWatch::lookFromWorker();
    expect(downAlerts('scheduler'))->toHaveCount(0);

    $this->travel(299)->seconds();
    SystemWatch::lookFromWorker();
    expect(downAlerts('scheduler'))->toHaveCount(0);

    $this->travel(1)->seconds();
    SystemWatch::lookFromWorker();
    expect(downAlerts('scheduler')->pluck('title')->all())->toBe(['المهام المجدولة متوقفة منذ 25 دقيقة']);
    $looked->setValue(null, null);
});

test('the worker looks between its jobs', function () {
    $looked = new ReflectionProperty(SystemWatch::class, 'workerLooked');
    $looked->setValue(null, null);

    event(new Looping('redis', 'default'));

    expect($looked->getValue())->toBe(now()->getTimestamp());
    $looked->setValue(null, null);
});

// ---- the queue ----------------------------------------------------------------------------------------------------

test('a job still waiting after ten minutes means nobody takes the jobs: the scheduler tells the admins', function () {
    $queue = watchedQueue();
    try {
        $this->freezeTime();
        SystemWatch::beat();
        SendSystemEmail::dispatch(0, 'x@example.org', new TemplatedEmail('admin_disk_space', ['المساحة' => '1', 'النسبة' => '1']));
        $this->travel(9)->minutes();
        SystemWatch::beat();
        $this->artisan('system:watch')->assertSuccessful();
        expect(downAlerts('queue'))->toHaveCount(0);
        $this->getJson('/up', monitorHeader())->assertOk();

        $this->travel(1)->minutes();
        SystemWatch::beat();
        $this->artisan('system:watch')->assertSuccessful();
        expect(downAlerts('queue'))->toHaveCount(1);   // the scheduler's watch, before anyone asks /up
        $this->getJson('/up', monitorHeader())->assertStatus(503)->assertJsonPath('checks.queue', false)->assertJsonPath('checks.scheduler', true);

        $alerts = downAlerts('queue');
        expect($alerts)->toHaveCount(1)->and($alerts[0]->title)->toBe('الطابور متوقف: مهمة تنتظر منذ 10 دقيقة')
            // Sent right away: the e-mail is not one more job waiting in the stopped queue.
            ->and(DB::table('email_log')->where('template_key', 'admin_service_down')->pluck('status')->all())->toBe(['sent'])
            ->and(Queue::size($queue))->toBe(1);

        // A worker takes the job: everything is right again.
        Queue::pop($queue)->delete();
        $this->getJson('/up', monitorHeader())->assertOk();
    } finally {
        forgetWatchedQueue($queue);
    }
});

// ---- /up ----------------------------------------------------------------------------------------------------------

test('/up says nothing more than which part works, keeps no session and is never cached', function () {
    SystemWatch::beat();

    $response = $this->get('/up', monitorHeader())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->headers->getCookies())->toBe([])
        ->and(array_keys($response->json('checks')))->toBe(['database', 'redis', 'scheduler', 'queue']);
});

test('without Redis, /up says Redis, and the beat and the jobs are not known', function () {
    SystemWatch::beat();
    config(['cache.default' => 'redis', 'queue.default' => 'redis', 'database.redis.default.port' => 1, 'database.redis.cache.port' => 1, 'database.redis.session.port' => 1]);
    foreach (['redis', 'cache', 'cache.store', 'queue'] as $name) {
        app()->forgetInstance($name);
        Facade::clearResolvedInstance($name);
    }

    $this->getJson('/up', monitorHeader())->assertStatus(503)->assertExactJson(['status' => 'failing', 'checks' => ['database' => true, 'redis' => false, 'scheduler' => false, 'queue' => false]]);

    expect(downAlerts('redis'))->toHaveCount(1)->and(downAlerts('scheduler'))->toHaveCount(0)->and(downAlerts('queue'))->toHaveCount(0);
});

// ---- the search engine --------------------------------------------------------------------------------------------

test('a search engine that does not answer: the visitor gets the database\'s results, the admins one alert', function () {
    config(['raqam.search.key' => 'k']);
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->getJson('/api/v1/search?q='.urlencode('الذهب'))->assertOk()->assertJsonPath('data.engine', 'database');
    Cache::forget('search:engine-down');   // the engine is tried again (after its 30 s)
    $this->getJson('/api/v1/search?q='.urlencode('الشيكل'))->assertOk()->assertJsonPath('data.engine', 'database');

    expect(downAlerts('search')->pluck('title')->all())->toBe(['محرك البحث لا يستجيب'])
        ->and(sentEmailsTo('admin@raqam.example'))->toHaveCount(1);
});

test('the watch is on the schedule every minute, never two at once', function () {
    $watch = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'system:watch'));

    expect($watch)->toHaveCount(1)->and($watch->first()->expression)->toBe('* * * * *')
        ->and($watch->first()->withoutOverlapping)->toBeTrue();
});
