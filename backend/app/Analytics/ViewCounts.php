<?php

namespace App\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * How many times a post was read, for visitors (22.12): hidden unless the admin turns on the switch
 * public_view_counts. When it is off the API sends no number at all, so the site cannot show one by mistake.
 */
final class ViewCounts
{
    public static function shown(): bool
    {
        return (bool) DB::table('feature_flags')->where('key', 'public_view_counts')->value('is_enabled');
    }

    /**
     * @param  iterable<int>  $postIds
     * @return array<int, int> post id => total views (0 for a post nobody read yet)
     */
    public static function totals(iterable $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($postIds) ? $postIds : iterator_to_array($postIds))));
        if ($ids === []) {
            return [];
        }
        $totals = DB::table('post_view_totals')->whereIn('post_id', $ids)->pluck('total_views', 'post_id')->map(fn ($n) => (int) $n)->all();

        return array_replace(array_fill_keys($ids, 0), $totals);
    }
}
