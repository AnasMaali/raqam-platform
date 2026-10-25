<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API reads only UTF-8. A query value or a body that is not valid UTF-8 is refused here (422) before anything echoes it
 * back or sends it to PostgreSQL, where it would end as a server error (phase 10b security review). Route parameters are
 * already checked by the router.
 */
final class RejectMalformedText
{
    public function handle(Request $request, Closure $next): Response
    {
        $valid = true;
        $query = $request->query->all();
        array_walk_recursive($query, function ($value, $key) use (&$valid) {
            $valid = $valid && mb_check_encoding((string) $key, 'UTF-8') && (! is_string($value) || mb_check_encoding($value, 'UTF-8'));
        });
        $body = $request->getContent();
        if (! $valid || ($body !== '' && ! mb_check_encoding($body, 'UTF-8'))) {
            return response()->json(['error' => ['code' => 'invalid', 'message' => 'أحد المعطيات غير صحيح.']], 422)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
