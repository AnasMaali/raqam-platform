<?php

use Illuminate\Support\Facades\Cache;

test('queues, cache and sessions use separate Redis databases', function () {
    $databases = collect(['default', 'cache', 'session'])
        ->map(fn (string $connection) => (string) config("database.redis.{$connection}.database"));

    expect($databases->unique())->toHaveCount(3)
        ->and(config('session.connection'))->toBe('session');
});

test('clearing the cache never logs anyone out', function () {
    $session = app('session')->driver('redis');
    $session->put('user', 'admin');
    $session->save();
    Cache::store('redis')->put('rates', 'cached', 60);

    $this->artisan('cache:clear', ['store' => 'redis'])->assertSuccessful();

    expect(Cache::store('redis')->get('rates'))->toBeNull()
        ->and($session->getHandler()->read($session->getId()))->toContain('admin');

    $session->getHandler()->destroy($session->getId());
});
