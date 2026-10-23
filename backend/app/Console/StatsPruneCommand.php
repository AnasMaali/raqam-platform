<?php

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Raw statistics keep `analytics.raw_retention_days` days (22.13, 90 by default); the daily figures are kept for ever.
 * The raw visitor counters live in Redis and expire there within two days; what this removes is the list of missing
 * pages: a path nobody reached for that long, or one resolved that long ago.
 */
class StatsPruneCommand extends Command
{
    protected $signature = 'stats:prune';

    protected $description = 'Delete missing-page records older than the raw statistics retention period';

    public function handle(): int
    {
        $days = max(1, (int) (DB::table('settings')->where('key', 'analytics.raw_retention_days')->value('value') ?? 90));
        $deleted = DB::table('not_found_paths')
            ->where(fn ($q) => $q->where('last_seen_at', '<', DB::raw("now() - make_interval(days => {$days})"))
                ->orWhere('resolved_at', '<', DB::raw("now() - make_interval(days => {$days})")))
            ->delete();
        $this->info("Deleted {$deleted} missing-page records (older than {$days} days).");

        return self::SUCCESS;
    }
}
