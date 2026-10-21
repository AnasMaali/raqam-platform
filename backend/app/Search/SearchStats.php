<?php

namespace App\Search;

use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What visitors look for (10.14): how often each term was searched per day, and how often it found nothing.
 * Only the normalised term and two counters are kept: no address, no visitor, no time of day. A search that
 * looks like a personal detail (an e-mail address, a phone number, a long number such as an ID or an account)
 * is not recorded at all, so the statistics never hold one.
 */
final class SearchStats
{
    public const MAX_TERM = 120;

    public static function record(string $query, bool $foundNothing): void
    {
        $term = mb_substr(ArabicNormalizer::normalize($query), 0, self::MAX_TERM);
        if (mb_strlen($term) < 2 || self::isPersonal($term)) {
            return;
        }

        // The day as the admin reads it (decision 1.5: stored in UTC, shown in Palestine time).
        $day = now('Asia/Hebron')->toDateString();

        DB::transaction(function () use ($term, $day, $foundNothing) {
            $id = DB::table('search_terms')->where('normalized_term', $term)->value('id');
            if ($id === null) {
                // A new word. Made-up queries sent in bulk would each add a row; past the day's allowance they are not recorded.
                $budget = 'search:new-terms:'.$day;
                Cache::add($budget, 0, now()->addDays(2));
                if (Cache::increment($budget) > (int) config('raqam.search.new_terms_per_day')) {
                    return;
                }
                DB::table('search_terms')->insertOrIgnore(['normalized_term' => $term]);
                $id = DB::table('search_terms')->where('normalized_term', $term)->value('id');
            }

            // One statement, so two visitors at the same moment both count.
            DB::statement(
                'INSERT INTO search_stats_daily (term_id, stat_date, searches, zero_result_searches) VALUES (?, ?, 1, ?)
                 ON CONFLICT (term_id, stat_date) DO UPDATE SET searches = search_stats_daily.searches + 1,
                   zero_result_searches = search_stats_daily.zero_result_searches + EXCLUDED.zero_result_searches',
                [$id, $day, $foundNothing ? 1 : 0],
            );
        });
    }

    /** An e-mail address, a phone number, or any number of six digits or more (the text is already normalised: Arabic digits are 0-9). */
    public static function isPersonal(string $normalised): bool
    {
        if (preg_match('/[^\s@]+@[^\s@]+/u', $normalised) === 1) {
            return true;
        }
        if (preg_match('/\d{6,}/', $normalised) === 1) {
            return true;
        }
        // A phone number written with spaces, dashes, dots or brackets: seven digits or more in one run of them.
        foreach (preg_split('/[^\d\s\-.()+\/]+/u', $normalised) as $run) {
            if (preg_match_all('/\d/', $run) >= 7) {
                return true;
            }
        }

        return false;
    }
}
