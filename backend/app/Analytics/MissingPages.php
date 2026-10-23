<?php

namespace App\Analytics;

use Illuminate\Support\Facades\DB;

/** The list of missing pages (22.10): a path is handled once a redirect leads away from it, or the admin says so. */
final class MissingPages
{
    public static function resolve(string ...$paths): int
    {
        return DB::table('not_found_paths')->whereIn('path', $paths)->whereNull('resolved_at')->update(['resolved_at' => DB::raw('now()')]);
    }

    public static function reopen(string ...$paths): int
    {
        return DB::table('not_found_paths')->whereIn('path', $paths)->update(['resolved_at' => null]);
    }
}
