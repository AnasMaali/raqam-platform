<?php

namespace App\Http\Controllers;

use App\Auth\AdminSessionLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two requests the warning dialog makes. Both need a signed-in admin, both are limited to 10 a
 * minute, and the first is the only one that counts as activity.
 */
class AdminSessionController
{
    /** The user pressed "I'm here": the middleware has already counted this request as activity. */
    public function keepAlive(AdminSessionLimits $limits): JsonResponse
    {
        return response()->json(['remaining' => $limits->remaining()]);
    }

    /**
     * How long the session has left, without extending it (the middleware does not count this
     * request). A GET, so the CSRF token must come in the header: only the panel's own script can send it.
     */
    public function status(Request $request, AdminSessionLimits $limits): JsonResponse
    {
        $token = $request->header('X-CSRF-TOKEN');
        abort_unless(is_string($token) && hash_equals($request->session()->token(), $token), 419);

        return response()->json(['remaining' => $limits->remaining()]);
    }
}
