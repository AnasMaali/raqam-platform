<?php

namespace App\Analytics;

use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** The list of missing pages (22.10): a path is handled once a redirect leads away from it, or the admin says so. */
final class MissingPages
{
    public static function resolve(string ...$paths): int
    {
        return self::mark($paths, resolved: true);
    }

    public static function reopen(string ...$paths): int
    {
        return self::mark($paths, resolved: false);
    }

    /** Marks the rows and writes each change to the audit trail (22.14); the path is the row's key, so it goes in the change. */
    private static function mark(array $paths, bool $resolved): int
    {
        $found = DB::table('not_found_paths')->whereIn('path', $paths)->when($resolved, fn ($q) => $q->whereNull('resolved_at'), fn ($q) => $q->whereNotNull('resolved_at'))->pluck('path');
        if ($found->isEmpty()) {
            return 0;
        }
        DB::table('not_found_paths')->whereIn('path', $found)->update(['resolved_at' => $resolved ? DB::raw('now()') : null]);
        foreach ($found as $path) {
            AuditLogger::record('update', 'not_found_paths', null, [$resolved ? 'resolved_path' : 'reopened_path' => [null, $path]]);
        }

        return $found->count();
    }
}
