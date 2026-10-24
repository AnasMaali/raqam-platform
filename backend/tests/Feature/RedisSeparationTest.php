<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Redis;

test('queues, cache and sessions use separate Redis databases', function () {
    $databases = collect(['default', 'cache', 'session'])
        ->map(fn (string $connection) => (string) config("database.redis.{$connection}.database"));

    expect($databases->unique())->toHaveCount(3)
        ->and(config('session.connection'))->toBe('session');
});

/*
| cache:clear empties the whole cache database (FLUSHDB). The test databases 6/7/8 are shared by the processes of a
| parallel run, so this test moves the cache and the sessions to databases 12 and 13, which nothing else uses, and
| refuses to run (fails) if either holds anything: it never empties a database that is not its own.
*/
test('clearing the cache never logs anyone out', function () {
    config(['database.redis.cache.database' => '12', 'database.redis.session.database' => '13']);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    app('cache')->forgetDriver('redis');
    foreach (['cache', 'session'] as $connection) {
        expect(Redis::connection($connection)->dbSize())->toBe(0, "Redis database of the {$connection} test connection is not empty; it is not this test's to clear.");
    }

    $session = app('session')->driver('redis');
    $session->put('user', 'admin');
    $session->save();
    Cache::store('redis')->put('rates', 'cached', 60);

    $this->artisan('cache:clear', ['store' => 'redis'])->assertSuccessful();

    expect(Cache::store('redis')->get('rates'))->toBeNull()
        ->and($session->getHandler()->read($session->getId()))->toContain('admin');

    $session->getHandler()->destroy($session->getId());
});

test('in a parallel run every process keeps its Redis keys under a prefix of its own', function () {
    $token = ParallelTesting::token();
    $prefix = (string) config('database.redis.options.prefix');
    $client = Redis::connection('cache')->client();

    expect($client->getOption($client::OPT_PREFIX))->toBe($prefix);
    if ($token !== false) {
        expect($prefix)->toEndWith("p{$token}:");
    }
});
