<?php

namespace App\Video;

use Illuminate\Support\Facades\DB;

/** Records how an outside service is doing in provider_health (decision 21.15). Times come from the database clock. */
final class ProviderHealth
{
    public static function success(string $provider): void
    {
        DB::table('provider_health')->where('provider_code', $provider)->update([
            'last_success_at' => DB::raw('now()'), 'consecutive_failures' => 0, 'last_error' => null, 'updated_at' => DB::raw('now()'),
        ]);
    }

    /** The message must already be free of keys and addresses that carry them. */
    public static function failure(string $provider, string $message): void
    {
        DB::table('provider_health')->where('provider_code', $provider)->update([
            'last_failure_at' => DB::raw('now()'), 'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'last_error' => mb_substr($message, 0, 1000), 'updated_at' => DB::raw('now()'),
        ]);
    }
}
