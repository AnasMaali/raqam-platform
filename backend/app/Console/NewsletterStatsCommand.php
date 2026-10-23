<?php

namespace App\Console;

use App\Newsletter\NewsletterIssues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Asks Brevo how the newsletters of the last eight weeks did, and notices the ones it sent (19.10). Hourly. */
class NewsletterStatsCommand extends Command
{
    protected $signature = 'newsletter:refresh-stats';

    protected $description = 'Refresh the newsletter numbers from Brevo';

    public function handle(NewsletterIssues $issues): int
    {
        $ids = DB::table('newsletter_issues')->whereNotNull('brevo_campaign_id')->where('week_start', '>=', now()->subWeeks(8)->toDateString())->pluck('id');
        foreach ($ids as $id) {
            $issues->stats((int) $id, fresh: true);
        }
        $this->info('Refreshed '.$ids->count().' newsletters.');

        return self::SUCCESS;
    }
}
