<?php

namespace App\Console;

use App\Markets\RateFetcher;
use Illuminate\Console\Command;

/** Fetches the official rates once (scheduled every 30 minutes, 12:00–16:00 Palestine time, on the bank's business days). */
class CurrencyFetchCommand extends Command
{
    protected $signature = 'currency:fetch';

    protected $description = 'Fetch the official exchange rates and keep any new publication';

    public function handle(RateFetcher $fetcher): int
    {
        $report = $fetcher->run();

        $this->info('Stored: '.(implode(', ', $report['stored']) ?: 'none').'. Unchanged: '.(implode(', ', $report['unchanged']) ?: 'none').'.');
        foreach ($report['problems'] as $code => $why) {
            $this->warn("{$code}: {$why}");
        }

        return $report['problems'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
