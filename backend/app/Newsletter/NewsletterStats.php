<?php

namespace App\Newsletter;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The newsletter's numbers for the panel (19.10): how many, and how that grew, week by week (Palestine weeks). */
final class NewsletterStats
{
    /**
     * @return array{active: int, unsubscribed: int, bounced: int, new_7: int, new_30: int}
     *
     * One reading of the table (QA-5): count(*) FILTER counts each number with its own condition in the same pass.
     */
    public static function totals(): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE status = 'active') AS active,
                   count(*) FILTER (WHERE status = 'unsubscribed') AS unsubscribed,
                   count(*) FILTER (WHERE status = 'bounced') AS bounced,
                   count(*) FILTER (WHERE status = 'active' AND confirmed_at > now() - make_interval(days => 7)) AS new_7,
                   count(*) FILTER (WHERE status = 'active' AND confirmed_at > now() - make_interval(days => 30)) AS new_30
            FROM subscribers
            SQL);

        return array_map('intval', (array) $row);
    }

    /**
     * @return list<array{week: string, joined: int, left: int, total: int}> the last $weeks weeks, oldest first
     *
     * One reading of the table for all the weeks (QA-5), not three queries a week: three FILTER counts per week.
     */
    public static function growth(int $weeks = 12, ?CarbonImmutable $now = null): array
    {
        $current = NewsletterIssues::weekStart($now ?? CarbonImmutable::now());
        $columns = [];
        $bindings = [];
        $starts = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $from = $current->subWeeks($i);
            $to = $from->addWeek();
            $n = count($starts);
            $starts[] = $from;
            $columns[] = "count(*) FILTER (WHERE confirmed_at >= ? AND confirmed_at < ?) AS joined_{$n}";
            array_push($bindings, $from->utc(), $to->utc());
            $columns[] = "count(*) FILTER (WHERE unsubscribed_at >= ? AND unsubscribed_at < ?) AS left_{$n}";
            array_push($bindings, $from->utc(), $to->utc());
            // Still subscribed at the end of that week: confirmed by then and not gone by then.
            $columns[] = "count(*) FILTER (WHERE confirmed_at < ? AND (status = 'active' OR unsubscribed_at >= ?)) AS total_{$n}";
            array_push($bindings, $to->utc(), $to->utc());
        }
        if ($starts === []) {
            return [];
        }
        $row = (array) DB::selectOne('SELECT '.implode(', ', $columns).' FROM subscribers', $bindings);

        return array_map(fn (int $n) => ['week' => $starts[$n]->toDateString(), 'joined' => (int) $row["joined_{$n}"],
            'left' => (int) $row["left_{$n}"], 'total' => (int) $row["total_{$n}"]], array_keys($starts));
    }
}
