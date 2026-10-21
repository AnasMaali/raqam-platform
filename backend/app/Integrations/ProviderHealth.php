<?php

namespace App\Integrations;

use Illuminate\Support\Facades\DB;

/**
 * Records how an outside service is doing in provider_health (decision 21.15). Times come from the database clock.
 * The third failure in a row, and every one after it, raises an alert for the admins (20.4), at most once an hour.
 */
final class ProviderHealth
{
    public const ALERT_AFTER = 3;

    public static function success(string $provider): void
    {
        DB::table('provider_health')->where('provider_code', $provider)->update([
            'last_success_at' => DB::raw('now()'), 'consecutive_failures' => 0, 'last_error' => null, 'updated_at' => DB::raw('now()'),
        ]);
    }

    /** The message should already be free of keys; it is scrubbed again here, in case one slipped through. */
    public static function failure(string $provider, string $message): void
    {
        $message = mb_substr(Secrets::scrub($message), 0, 1000);

        $row = DB::selectOne(
            'UPDATE provider_health SET last_failure_at = now(), consecutive_failures = consecutive_failures + 1,
                    last_error = ?, updated_at = now()
             WHERE provider_code = ? RETURNING consecutive_failures',
            [$message, $provider],
        );

        if ($row !== null && (int) $row->consecutive_failures >= self::ALERT_AFTER) {
            ProviderAlerts::raise($provider, (int) $row->consecutive_failures, $message);
        }
    }
}
