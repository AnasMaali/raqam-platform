<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Always JSON; a validator (ETag) on every 200 and a short shared-cache lifetime; 304 when the client already has it (decision 21.16 keeps the site fresh by webhook). */
class ApiResponseCache
{
    private const BROWSER = 30;

    private const SHARED = 120;

    private const STALE = 300;

    private const TRUE_FOR = 'api.true_for';

    /**
     * The answer changes by itself in $seconds (a special offer ends): no cache may keep it, or serve it stale, past
     * that moment. Null leaves the usual lifetimes.
     */
    public static function trueFor(Request $request, ?int $seconds): void
    {
        $request->attributes->set(self::TRUE_FOR, $seconds);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200) {
            $etag = '"'.md5((string) $response->getContent()).'"';
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', $this->lifetime($request->attributes->get(self::TRUE_FOR)));

            if (in_array($etag, array_map('trim', explode(',', str_replace('W/', '', (string) $request->header('If-None-Match')))), true)) {
                $response->setStatusCode(304)->setContent('');
            }
        } else {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    /** Fresh for a short while, then served stale while it is fetched again; all of it within $trueFor seconds. */
    private function lifetime(?int $trueFor): string
    {
        $limit = $trueFor === null ? PHP_INT_MAX : max(0, $trueFor);
        $shared = min(self::SHARED, $limit);

        return sprintf('public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d', min(self::BROWSER, $limit), $shared, min(self::STALE, $limit - $shared));
    }
}
