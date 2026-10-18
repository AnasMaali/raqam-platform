<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Decision 24.14: a raw IP address is never stored. Rate-limit counters live in Redis under a key, so the key
 * must not contain the address either.
 */
const A_VISITOR = '203.0.113.77';

function requestFrom(string $ip, string $uri = '/api/v1/posts'): Request
{
    return Request::create($uri, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
}

test('the limiters of the API and of the panel do not put the visitor\'s address in their keys', function (string $limiter) {
    $limit = RateLimiter::limiter($limiter)(requestFrom(A_VISITOR));
    $limits = is_array($limit) ? $limit : [$limit];

    foreach ($limits as $one) {
        expect($one->key)->not->toContain(A_VISITOR)->not->toContain(sha1(A_VISITOR));
    }
})->with(['api-public', 'admin-session']);

test('the same visitor still gets the same key, and another visitor another one', function () {
    $key = fn (string $ip) => RateLimiter::limiter('api-public')(requestFrom($ip))->key;

    expect($key(A_VISITOR))->toBe($key(A_VISITOR))->and($key(A_VISITOR))->not->toBe($key('203.0.113.78'));
});

test('no route uses an unnamed throttle, whose key is a bare hash of the address', function () {
    $unnamed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && preg_match('/^throttle:\d+/', $m)))
        ->map(fn ($route) => $route->uri())->values()->all();

    expect($unnamed)->toBe([]);
});
