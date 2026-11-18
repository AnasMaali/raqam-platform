<?php

namespace App\Http\Middleware;

use App\Panel\SystemWatch;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * When Redis does not answer (QA-6, docs/qa/resilience.md), the website keeps its news: what the API is asked to read
 * is read from the database, with a cache that lasts this one request (request limits included). The forms answer 503
 * at once, before anything is written (their limits, codes and mails live in Redis); the panel answers 503 as soon as
 * its session is asked for. The admins are told (SystemWatch), and nothing needs to be done
 * when Redis is back: the next request finds it.
 */
class RedisOutage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up') || ! SystemWatch::usesRedis() || SystemWatch::redisUp()) {
            return $next($request);
        }

        // always(): deferred work is skipped after an answer of 400 or more unless told otherwise, and this one is 503.
        defer(fn () => SystemWatch::redisDown())->always();

        // The panel and the other pages go on: the first thing that needs Redis (the session) fails, and that failure
        // is answered with the plain 503 page (bootstrap/app.php).
        if (! $request->is('api/*')) {
            return $next($request);
        }
        if (! $request->isMethodSafe()) {
            return self::unavailable();
        }

        // The repository itself takes a store of this request: whoever already holds it (the request limiter) uses it too.
        // A session, if anything asks for one, lasts this request as well.
        $cache = config('cache.default') === 'redis' ? Cache::store('redis') : null;
        $shared = $cache?->getStore();
        $session = config('session.driver');
        $cache?->setStore(new ArrayStore);
        config(['session.driver' => 'array']);
        try {
            return $next($request);
        } finally {
            $shared === null || $cache->setStore($shared);
            config(['session.driver' => $session]);
        }
    }

    public static function unavailable(): Response
    {
        return response()->json(['error' => ['code' => 'unavailable', 'message' => 'الخدمة غير متاحة مؤقتاً. حاول بعد قليل.']], 503, ['Retry-After' => '60']);
    }
}
