<?php

namespace App\Console;

use App\Newsletter\NewsletterIssues;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Makes this week's newsletter draft once its day and time (settings) have come (19.6). Runs every 15 minutes. */
class NewsletterWeeklyDraftCommand extends Command
{
    protected $signature = 'newsletter:weekly-draft {--now : Make it now, whatever the day}';

    protected $description = 'Make this week\'s newsletter draft (and hand it to Brevo as a draft) when its time has come';

    public function handle(NewsletterIssues $issues): int
    {
        if (! $this->option('now') && ! $issues->isDue(CarbonImmutable::now())) {
            $this->line('Not due.');

            return self::SUCCESS;
        }
        $id = $issues->createDraft();
        $this->info($id === null ? 'This week\'s draft exists already.' : "Made draft #{$id}.");

        return self::SUCCESS;
    }
}
