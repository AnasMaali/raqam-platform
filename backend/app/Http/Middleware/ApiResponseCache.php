<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Always JSON; a validator (ETag) on every 200 and a short shared-cache lifetime; 304 when the client already has it (decision 21.16 keeps the site fresh by webhook). */
class ApiResponseCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        if ($request->isMethod('GET') && $response->getStatusCode() === 200) {
            $etag = '"'.md5((string) $response->getContent()).'"';
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', 'public, max-age=30, s-maxage=120, stale-while-revalidate=300');

            if (in_array($etag, array_map('trim', explode(',', str_replace('W/', '', (string) $request->header('If-None-Match')))), true)) {
                $response->setStatusCode(304)->setContent('');
            }
        } else {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
