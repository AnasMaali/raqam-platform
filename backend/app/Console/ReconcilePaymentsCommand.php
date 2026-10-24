<?php

namespace App\Console;

use App\Payments\Payments;
use App\Payments\Refunds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every 15 minutes (16.7): asks the bank about every attempt still pending after `raqam.payments.stale_minutes`, and
 * finishes refunds that were interrupted before the bank answered. Nothing to do while online payments are off.
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Ask the bank about stuck payments and finish interrupted refunds';

    public function handle(Payments $payments, Refunds $refunds): int
    {
        if (! Payments::on()) {
            $this->line('online payments are off');

            return self::SUCCESS;
        }
        $stale = max(1, (int) config('raqam.payments.stale_minutes'));
        $settled = 0;
        foreach (DB::table('payments')->where('status', 'pending')->where('created_at', '<', DB::raw("now() - make_interval(mins => {$stale})"))->orderBy('id')->pluck('id') as $id) {
            try {
                $settled += $payments->sync((int) $id) === 'pending' ? 0 : 1;
            } catch (\Throwable $e) {
                report($e);   // one bank error must not stop the others
            }
        }
        foreach (DB::table('refunds')->where('status', 'pending')->where('created_at', '<', DB::raw("now() - make_interval(mins => {$stale})"))->orderBy('id')->pluck('id') as $id) {
            try {
                $refunds->send((int) $id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("payments settled: {$settled}");

        return self::SUCCESS;
    }
}
