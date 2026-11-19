<?php

namespace App\Http\Controllers;

use App\Panel\SystemWatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The two health addresses (docs/deployment-requirements.md, "Watching the platform").
 *
 * `/up`, for the monitor outside the server only: it sends HEALTH_TOKEN in the X-Health-Token header, and anyone else
 * gets the 404 of a page that does not exist, with nothing looked at. 200 when every part works, 503 when one does
 * not, and which one: only the parts' names and true/false, no error, address or number. Whatever it finds stopped is
 * also told to the admins (once an hour each), so a server where the scheduler and the queue worker both stopped is
 * still noticed the next time the monitor asks.
 *
 * `/livez`, for the container's health check: PHP answers. It asks nothing of the database, Redis, the scheduler or
 * the workers, and answers during maintenance too, so none of them stopping gets the web server restarted.
 */
class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('raqam.health.token');
        $sent = $request->header('X-Health-Token');
        if ($token === '' || ! is_string($sent) || ! hash_equals($token, $sent)) {
            abort(404);
        }

        $checks = SystemWatch::report();

        defer(function () use ($checks) {
            $checks['redis'] || SystemWatch::redisDown();
            if ($checks['redis']) {
                $checks['scheduler'] || SystemWatch::schedulerStopped(SystemWatch::schedulerLate() ?? -1);
                $checks['queue'] || SystemWatch::queueStopped(SystemWatch::queueLate() ?? 0);
            }
        })->always();   // run after the answer even when it is a 503 (deferred work is skipped after errors otherwise)

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'failing', 'checks' => $checks], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    public function live(): Response
    {
        return response('ok', 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
