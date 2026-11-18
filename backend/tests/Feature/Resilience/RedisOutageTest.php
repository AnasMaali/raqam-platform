<?php

use App\Ads\AdClickLink;
use App\Http\Middleware\RedisOutage;
use App\Panel\SystemWatch;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Redis stops answering (QA-6, docs/qa/resilience.md). The website keeps its news (the API's reads come from the
 * database), everything that needs Redis answers 503 before writing anything, the admins are told once, and when Redis
 * answers again everything works as before without anyone doing anything.
 */

uses(RefreshDatabase::class);

const OUTAGE_SITE_TOKEN = 'outage-site-token';

beforeEach(function () {
    // Deferred work runs after the answer as on the server (not at once): an answer of 503 must still raise the alert.
    config(['mail.default' => 'array', 'raqam.api.site_token' => OUTAGE_SITE_TOKEN, 'raqam.turnstile.secret' => 'turnstile-secret', 'raqam.turnstile.hostnames' => 'raqam.ps']);
    Http::swap(new Factory);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'contact', 'hostname' => 'raqam.ps'])]);
    $this->admin = staffAdmin('admin@raqam.example');
    $this->post = insertPost(publishedPostValues(['published_at' => now()->subMinute()]));
    // As in production: the cache, the sessions and the queue in Redis (the test databases of Redis, phpunit.xml).
    $this->ports = collect(['default', 'cache', 'session'])->mapWithKeys(fn ($c) => [$c => config("database.redis.{$c}.port")])->all();
    // A prefix of this test: the request limits and the gaps between clicks it counts in Redis never reach another run.
    config(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis', 'cache.prefix' => 'qa6-'.bin2hex(random_bytes(6)).':']);
    redisConnectionsAgain();
});

afterEach(function () {
    redisAnswersAgain($this->ports);
});

/** Every Redis connection made again from the configuration, as a new request on the server would. */
function redisConnectionsAgain(): void
{
    foreach (['redis', 'redis.connection', 'cache', 'cache.store', 'queue', 'queue.connection', 'session', 'session.store'] as $name) {
        app()->forgetInstance($name);
    }
    foreach (['redis', 'cache', 'cache.store', 'queue', 'session'] as $name) {
        Facade::clearResolvedInstance($name);
    }
    // The request limiter holds the cache it was made with, at boot: in production that is Redis's.
    $limiter = app(RateLimiter::class);
    (new ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store(config('cache.default')));
}

/** Nothing listens on port 1: every connection is refused, as when Redis has stopped. */
function redisStopsAnswering(): void
{
    foreach (['default', 'cache', 'session'] as $c) {
        config(["database.redis.{$c}.port" => 1]);
    }
    redisConnectionsAgain();
}

function redisAnswersAgain(array $ports): void
{
    foreach ($ports as $c => $port) {
        config(["database.redis.{$c}.port" => $port]);
    }
    redisConnectionsAgain();
}

function outageContact(): TestResponse
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => OUTAGE_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7', 'User-Agent' => PERSON_AGENT])
        ->postJson('/api/v1/contact', ['name' => 'سامي الخطيب', 'email' => 'sami@example.com', 'phone' => '', 'subject' => 'استفسار',
            'message' => 'مرحباً، أريد أن أعلن.', 'turnstile_token' => 'tok', 'website' => '']);
}

function outageRead(string $path): TestResponse
{
    return test()->flushHeaders()->withHeaders(['X-Raqam-Site-Token' => OUTAGE_SITE_TOKEN])->getJson($path);
}

test('the news stays: what the API is asked to read comes from the database', function () {
    redisStopsAnswering();

    outageRead('/api/v1/posts/'.$this->post)->assertOk()->assertJsonPath('data.id', $this->post);
    outageRead('/api/v1/site')->assertOk();
    outageRead('/api/v1/posts')->assertOk()->assertJsonPath('data.0.id', $this->post);
    outageRead('/api/v1/currencies')->assertOk();
    outageRead('/api/v1/home')->assertOk();
    // The request limit counts with this request's cache too, instead of failing.
    $this->flushHeaders()->getJson('/api/v1/posts')->assertOk();
    // The cache of the request ends with it: the next one finds Redis again if it is back.
    expect(Cache::store('redis')->getStore())->toBeInstanceOf(RedisStore::class);
});

test('the forms answer 503 at once, before anything is written', function () {
    redisStopsAnswering();

    outageContact()->assertStatus(503)->assertHeader('Retry-After', '60')
        ->assertExactJson(['error' => ['code' => 'unavailable', 'message' => 'الخدمة غير متاحة مؤقتاً. حاول بعد قليل.']]);

    expect(DB::table('contact_messages')->count())->toBe(0);
});

test('the panel answers a plain page in Arabic, not an error of the server', function () {
    redisStopsAnswering();

    $this->get('/test-panel/login')->assertStatus(503)->assertSee('الخدمة غير متاحة مؤقتاً')->assertSee('dir="rtl"', false);
});

test('the admins are told once, by the bell and by an e-mail sent right away', function () {
    redisStopsAnswering();

    // Only answers of 503: the alert goes out after them too.
    outageContact()->assertStatus(503);
    expect(DB::table('admin_notifications')->where('dedupe_key', 'down:redis')->count())->toBe(1);
    $this->get('/test-panel/login')->assertStatus(503);
    outageRead('/api/v1/site')->assertOk();

    $alerts = DB::table('admin_notifications')->where('dedupe_key', 'down:redis')->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->user_id)->toBe($this->admin)
        ->and($alerts[0]->title)->toBe('Redis لا يستجيب')
        ->and(DB::table('email_log')->where('template_key', 'admin_service_down')->pluck('status')->all())->toBe(['sent'])
        ->and(sentEmailsTo('admin@raqam.example'))->toHaveCount(1);
});

test('when Redis answers again, everything works as before without anyone doing anything', function () {
    redisStopsAnswering();
    outageContact()->assertStatus(503);

    redisAnswersAgain($this->ports);

    outageContact()->assertStatus(202);
    $this->get('/test-panel/login')->assertOk();
    outageRead('/api/v1/posts/'.$this->post)->assertOk();
    expect(DB::table('contact_messages')->count())->toBe(1)
        ->and(DB::table('admin_notifications')->where('dedupe_key', 'down:redis')->count())->toBe(1);
});

test('Redis stopping halfway through a request is a 503 too, never a 500', function () {
    Route::get('/api/v1/qa-outage-probe', fn () => throw new RedisException('Connection lost'));
    Route::get('/qa-outage-probe', fn () => throw new RedisException('Connection lost'));

    $this->getJson('/api/v1/qa-outage-probe')->assertStatus(503)->assertJsonPath('error.code', 'unavailable');
    $this->get('/qa-outage-probe')->assertStatus(503)->assertSee('الخدمة غير متاحة مؤقتاً');
    expect(DB::table('admin_notifications')->where('dedupe_key', 'down:redis')->count())->toBe(1);
});

test('a Redis that does not answer is asked once per request, and found down', function () {
    redisStopsAnswering();

    expect(SystemWatch::redisUp())->toBeFalse()->and(SystemWatch::redisUp())->toBeFalse();
    expect(RedisOutage::unavailable()->getStatusCode())->toBe(503);
});

test('a machine without Redis in its settings is left alone', function () {
    config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
    redisStopsAnswering();

    outageContact()->assertStatus(202);
    expect(DB::table('admin_notifications')->where('dedupe_key', 'down:redis')->count())->toBe(0);
});

test('Redis and the mail server that do not answer cost seconds, not the 60 of PHP', function () {
    foreach (['default', 'cache', 'session'] as $c) {
        expect(config("database.redis.{$c}.timeout"))->toBeGreaterThan(0)->toBeLessThanOrEqual(2)
            ->and(config("database.redis.{$c}.read_timeout"))->toBeGreaterThan(0)->toBeLessThanOrEqual(3);
    }
    expect(config('mail.mailers.smtp.timeout'))->toBeGreaterThan(0)->toBeLessThanOrEqual(15);

    // The settings reach the connection itself.
    expect(Redis::connection('cache')->client()->getTimeout())->toEqual(config('database.redis.cache.timeout'))
        ->and(Redis::connection('cache')->client()->getReadTimeout())->toEqual(config('database.redis.cache.read_timeout'));
    Cache::store('array')->flush();
});

test('an ad click while Redis is down still leads to the advertiser, and is not counted (no gap between clicks can be kept)', function () {
    $c = adCampaign();
    $link = '/api/v1/ads/click/'.$c->id.'/header_leaderboard/'.AdClickLink::signature($c->id, 'header_leaderboard');
    redisStopsAnswering();

    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($link)->assertStatus(302)->assertHeader('Location', 'https://bank.example.ps/offer?utm=raqam');
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($link)->assertStatus(302);
    expect(DB::table('ad_stats_daily')->sum('clicks'))->toEqual(0);

    redisAnswersAgain($this->ports);
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->get($link)->assertStatus(302);
    expect(DB::table('ad_stats_daily')->sum('clicks'))->toEqual(1);
});
