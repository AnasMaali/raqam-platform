<?php

namespace App\Http\Middleware;

use App\Accounts\CustomerSessions;
use App\Api\SiteToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in customer's request (decision 17): from the site's server (its token and the visitor's address) with the
 * customer's session as `Authorization: Bearer`. The customer is put on the request as `customer`; never on the
 * framework's auth guard, so nothing of the panel can ever see a customer as signed in.
 */
final class RequireCustomer
{
    public function __construct(private CustomerSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (SiteToken::visitor($request) === null) {
            return response()->json(['error' => ['code' => 'site_only', 'message' => 'هذا الطلب يُرسَل من الموقع فقط.']], 403);
        }
        $user = $this->sessions->user($request->bearerToken());
        if ($user === null) {
            return response()->json(['error' => ['code' => 'signed_out', 'message' => 'سجّل الدخول من جديد.']], 401)->withHeaders(['Cache-Control' => 'no-store']);
        }
        $request->attributes->set('customer', $user);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
