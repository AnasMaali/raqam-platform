<?php

namespace App\Search;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The figures of the search statistics screen for a period of days (Palestine time, as they were counted). */
final class SearchStatsReport
{
    public const ROWS = 50;

    public const MAX_DAYS = 366;

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
        $today = CarbonImmutable::now('Asia/Hebron')->startOfDay();
        $problem = null;

        if ($period === 'custom') {
            $start = self::day($from) ?? $today->subDays(6);
            $end = self::day($to) ?? $today;
            if ($start->greaterThan($end)) {
                [$start, $end, $problem] = [$end, $start, 'بداية الفترة بعد نهايتها، فبُدّلتا.'];
            }
            if ($start->diffInDays($end) >= self::MAX_DAYS) {
                [$start, $problem] = [$end->subDays(self::MAX_DAYS - 1), 'الفترة أطول من سنة، فعُرضت آخر سنة منها.'];
            }
        } else {
            $days = in_array($period, ['1', '7', '30'], true) ? (int) $period : 7;
            [$start, $end] = [$today->subDays($days - 1), $today];
        }

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

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10), 'Asia/Hebron')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
