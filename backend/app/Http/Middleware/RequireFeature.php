<?php

namespace App\Http\Middleware;

use App\Settings\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route of a system that is built but switched off (decisions 15.13, 16.13, 17.1): while its switch is off the
 * route does not exist — a plain 404, the same as an address that was never there, before anything else is checked.
 * Used as `feature:booking`; several keys (`feature:booking,online_payments`) must all be on.
 */
final class RequireFeature
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        foreach ($keys as $key) {
            if (! FeatureFlags::on($key)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
