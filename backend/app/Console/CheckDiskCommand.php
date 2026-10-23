<?php

namespace App\Console;

use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;
use Illuminate\Console\Command;

/** The server's disk is nearly full (20.4): the bell and an e-mail, at most once an hour (20.11). */
class CheckDiskCommand extends Command
{
    protected $signature = 'system:check-disk';

    protected $description = 'Alert the admins when the server disk is nearly full';

    public function handle(): int
    {
        $path = (string) (config('raqam.alerts.disk_path') ?: storage_path());
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            $this->error('Cannot read the disk of the storage folder.');

            return self::FAILURE;
        }

        $percent = $free / $total * 100;
        $low = $percent < (float) config('raqam.alerts.disk_min_free_percent') || $free < (float) config('raqam.alerts.disk_min_free_gb') * 1024 ** 3;
        $size = number_format($free / 1024 ** 3, 1).' GB';
        $share = number_format($percent, 1).'%';
        $this->info("Free: {$size} ({$share}).");

        if ($low) {
            AdminAlerts::raise('system', 'disk', 'مساحة السيرفر قاربت على النفاد', "المساحة الحرة {$size} ({$share}).", null,
                fn () => new TemplatedEmail('admin_disk_space', ['المساحة' => $size, 'النسبة' => $share]));
        }

        return self::SUCCESS;
    }
}
