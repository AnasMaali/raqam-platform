<?php

namespace App\Console;

use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Many failed sign-ins to the panel in the last hour (24.15): the bell and an e-mail, at most once an hour. It only reads
 * the sign-in attempts the sign-in already writes (as HMACs, never an address); the sign-in itself is untouched.
 */
class WatchSignInFailuresCommand extends Command
{
    protected $signature = 'auth:watch-failures';

    protected $description = 'Alert the admins when the panel sees many failed sign-ins in an hour';

    public function handle(): int
    {
        $row = DB::selectOne("SELECT count(*) AS n, count(DISTINCT email) AS accounts FROM login_attempts
                              WHERE NOT succeeded AND attempted_at > now() - interval '1 hour'");
        $count = (int) $row->n;
        $this->info("Failed sign-ins in the last hour: {$count}.");

        if ($count >= (int) config('raqam.alerts.failed_signins_per_hour')) {
            AdminAlerts::raise('security', 'failed-signins', "{$count} محاولة دخول فاشلة خلال ساعة",
                'على '.$row->accounts.' بريد مختلف. راجع «محاولات الدخول».', 'login-attempts',
                fn () => new TemplatedEmail('admin_failed_signins', ['العدد' => (string) $count, 'الحسابات' => (string) $row->accounts]));
        }

        return self::SUCCESS;
    }
}
