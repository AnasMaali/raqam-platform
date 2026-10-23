<?php

namespace App\Console;

use App\Ads\AdAlerts;
use Illuminate\Console\Command;

class AdEndingAlertsCommand extends Command
{
    protected $signature = 'ads:ending-alerts';

    protected $description = 'Alert the admins about active campaigns that end within three days (once per campaign and end)';

    public function handle(): int
    {
        $this->info('Alerted about '.AdAlerts::sendEnding().' campaigns.');

        return self::SUCCESS;
    }
}
