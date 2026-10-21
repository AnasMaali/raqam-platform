<?php

namespace App\Integrations;

use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Which outside source serves each service (decisions 11, 12.13): the one data_providers row that is active and
 * not a backup. Switching is a setting, not a code change; it is written to the audit log.
 */
final class DataProviders
{
    public static function active(string $service): ?string
    {
        return DB::table('data_providers')->where('service', $service)->where('is_active', true)->where('is_backup', false)->value('code');
    }

    /** @return array<string, string> code => name, for one service */
    public static function of(string $service): array
    {
        return DB::table('data_providers')->where('service', $service)->orderBy('is_backup')->orderBy('code')->pluck('name', 'code')->all();
    }

    /** Makes $code the source of its service; the others become inactive backups. */
    public static function switchTo(string $service, string $code): void
    {
        DB::transaction(function () use ($service, $code) {
            DB::table('data_providers')->where('service', $service)->lockForUpdate()->get();
            if (! DB::table('data_providers')->where('service', $service)->where('code', $code)->exists()) {
                throw new InvalidArgumentException("{$code} is not a {$service} provider");
            }

            $before = self::active($service);
            if ($before === $code) {
                return;
            }

            // Demote first: the database allows one active primary per service at any moment.
            DB::table('data_providers')->where('service', $service)->where('code', '<>', $code)->update(['is_active' => false, 'is_backup' => true]);
            DB::table('data_providers')->where('code', $code)->update(['is_active' => true, 'is_backup' => false]);

            AuditLogger::record('settings', null, null, ["provider.{$service}" => [$before, $code]]);
        });
    }
}
