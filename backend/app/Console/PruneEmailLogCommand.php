<?php

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The e-mail log keeps 90 days (20.9); older rows go. */
class PruneEmailLogCommand extends Command
{
    protected $signature = 'emails:prune';

    protected $description = 'Delete e-mail log rows older than the retention period';

    public function handle(): int
    {
        $days = (int) config('raqam.mail.retention_days');
        $deleted = DB::table('email_log')->where('queued_at', '<', DB::raw("now() - make_interval(days => {$days})"))->delete();
        $this->info("Deleted {$deleted} e-mail log rows.");

        return self::SUCCESS;
    }
}
