<?php

namespace App\Newsletter;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The newsletter's numbers for the panel (19.10): how many, and how that grew, week by week (Palestine weeks). */
final class NewsletterStats
{
    /** @return array{active: int, unsubscribed: int, bounced: int, new_7: int, new_30: int} */
    public static function totals(): array
    {
        $count = fn (string $status) => DB::table('subscribers')->where('status', $status)->count();
        $since = fn (int $days) => DB::table('subscribers')->where('status', 'active')->where('confirmed_at', '>', DB::raw("now() - make_interval(days => {$days})"))->count();

        return ['active' => $count('active'), 'unsubscribed' => $count('unsubscribed'), 'bounced' => $count('bounced'), 'new_7' => $since(7), 'new_30' => $since(30)];
    }

    /** @return list<array{week: string, joined: int, left: int, total: int}> the last $weeks weeks, oldest first */
    public static function growth(int $weeks = 12, ?CarbonImmutable $now = null): array
    {
        $current = NewsletterIssues::weekStart($now ?? CarbonImmutable::now());
        $rows = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $from = $current->subWeeks($i);
            $to = $from->addWeek();
            $joined = DB::table('subscribers')->whereNotNull('confirmed_at')->where('confirmed_at', '>=', $from->utc())->where('confirmed_at', '<', $to->utc())->count();
            $left = DB::table('subscribers')->whereNotNull('unsubscribed_at')->where('unsubscribed_at', '>=', $from->utc())->where('unsubscribed_at', '<', $to->utc())->count();
            // Still subscribed at the end of that week: confirmed by then and not gone by then.
            $total = DB::table('subscribers')->where('confirmed_at', '<', $to->utc())
                ->where(fn ($q) => $q->where('status', 'active')->orWhere('unsubscribed_at', '>=', $to->utc()))->count();
            $rows[] = ['week' => $from->toDateString(), 'joined' => $joined, 'left' => $left, 'total' => $total];
        }

        return $rows;
    }
}
