<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('devices:prune')->daily();

Schedule::command('queue:prune-failed --hours=720')->daily();

Schedule::command('posts:publish-due')->everyMinute()->withoutOverlapping();
Schedule::command('posts:purge-trashed')->daily();
Schedule::command('videos:check-availability')->weeklyOn(0, '04:00')->withoutOverlapping();

// Search (10.8): the every-minute net under the indexing jobs, and a nightly rebuild that repairs any drift.
Schedule::command('search:sync-due')->everyMinute()->withoutOverlapping();
Schedule::command('search:reindex')->dailyAt('03:30')->withoutOverlapping();
