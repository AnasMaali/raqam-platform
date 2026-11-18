<?php

namespace App\Http\Controllers;

use App\Panel\SystemWatch;
use Illuminate\Http\JsonResponse;

/**
 * `/up`, for a monitor outside the server (docs/deployment-requirements.md): 200 when every part works, 503 when one
 * does not, and which one. Only the parts' names and true/false: no error, address or number. Whatever it finds
 * stopped is also told to the admins (once an hour each), so a server where the scheduler and the queue worker both
 * stopped is still noticed the next time the monitor asks.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
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
}
