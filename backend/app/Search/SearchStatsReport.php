<?php

namespace App\Search;

use App\Analytics\StatsPeriod;
use Illuminate\Support\Facades\DB;

/** The figures of the search statistics screen for a period of days (Palestine time, as they were counted). */
final class SearchStatsReport
{
    public const ROWS = 50;

    /**
     * @param  list<object{term: string, searches: int, zero: int}>  $top
     * @param  list<object{term: string, searches: int, zero: int}>  $nothingFound
     */
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $searches,
        public readonly int $zero,
        public readonly array $top,
        public readonly array $nothingFound,
        public readonly ?string $problem = null,
    ) {}

    public static function for(string $period, mixed $from = null, mixed $to = null): self
    {
        $resolved = StatsPeriod::of($period, $from, $to);
        $problem = $resolved->problem;
        [$start, $end] = [$resolved->from, $resolved->to];

        $range = [$start->toDateString(), $end->toDateString()];
        $rows = fn () => DB::table('search_stats_daily')->join('search_terms', 'search_terms.id', '=', 'search_stats_daily.term_id')
            ->whereBetween('stat_date', $range)->groupBy('search_terms.id', 'search_terms.normalized_term')
            ->selectRaw('search_terms.normalized_term AS term, sum(searches)::int AS searches, sum(zero_result_searches)::int AS zero');
        $totals = DB::table('search_stats_daily')->whereBetween('stat_date', $range)
            ->selectRaw('coalesce(sum(searches), 0)::int AS searches, coalesce(sum(zero_result_searches), 0)::int AS zero')->first();

        return new self(
            $range[0], $range[1], (int) $totals->searches, (int) $totals->zero,
            $rows()->orderByDesc('searches')->orderBy('term')->limit(self::ROWS)->get()->all(),
            $rows()->havingRaw('sum(zero_result_searches) > 0')->orderByDesc('zero')->orderBy('term')->limit(self::ROWS)->get()->all(),
            $problem,
        );
    }
}
