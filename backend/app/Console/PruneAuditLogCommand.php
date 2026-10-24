<?php

namespace App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail and the sign-in attempts keep two years (22.17), or longer if the setting audit.retention_days says
 * so; never less. The database refuses to delete an audit row younger than two years whatever the application asks,
 * and allows the older ones only inside a transaction that says it is pruning (raqam.audit_prune), so this is the one
 * way rows ever leave the trail.
 */
class PruneAuditLogCommand extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Delete audit log rows and sign-in attempts older than two years (or the longer retention setting)';

    public function handle(): int
    {
        $days = max(0, (int) DB::table('settings')->where('key', 'audit.retention_days')->value('value'));
        // The older of "two years ago" (what the database allows) and the setting.
        $cutoff = DB::raw("least(now() - interval '2 years', now() - make_interval(days => {$days}))");

        [$audit, $attempts] = DB::transaction(function () use ($cutoff) {
            DB::statement("SET LOCAL raqam.audit_prune = 'on'");

            return [
                DB::table('audit_log')->where('occurred_at', '<', $cutoff)->delete(),
                DB::table('login_attempts')->where('attempted_at', '<', $cutoff)->delete(),
            ];
        });

        $this->info("Deleted {$audit} audit rows and {$attempts} sign-in attempts older than the retention period.");

        return self::SUCCESS;
    }
}
