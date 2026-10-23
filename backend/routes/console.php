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

// Currency board (decision 11, revised 2026-10-12): the Bank of Israel publishes once a business day (Monday to Friday)
// in the afternoon (seen 15:21–15:42 Palestine time); checking every half hour from 12:00 to 17:30 stores a rate only when its
// publication changes, and leaves room for a late publication so a day is not lost.
// The time zone comes first: between() reads it when the schedule is built, not when it runs.
Schedule::command('currency:fetch')->timezone('Asia/Hebron')->weekdays()->everyThirtyMinutes()
    ->between(config('raqam.currency.window_from'), config('raqam.currency.window_to'))->withoutOverlapping();

// Weather (decision 12.2): each visible city once an hour, spread over the minutes of the hour.
Schedule::command('weather:fetch --due')->everyMinute()->withoutOverlapping();

// System e-mail and alerts (decision 20): the log keeps 90 days (20.9); the 3-day warning of a campaign (13.9); the disk
// (20.4); the weekly summary for the admins (20.12), Saturday morning in Palestine.
Schedule::command('emails:prune')->dailyAt('02:30')->withoutOverlapping();
// Chatbot conversations after 180 days and contact messages after 365 (decision 2026-10-24).
Schedule::command('retention:prune')->dailyAt('02:40')->withoutOverlapping();
Schedule::command('ads:ending-alerts')->hourly()->withoutOverlapping();
Schedule::command('system:check-disk')->hourly()->withoutOverlapping();
Schedule::command('admin:weekly-digest')->timezone('Asia/Hebron')->weeklyOn(6, '09:00')->withoutOverlapping();

// Newsletter (19.6, 19.10): the weekly draft on the admin's day and time (checked every quarter hour), Brevo's numbers hourly.
Schedule::command('newsletter:weekly-draft')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('newsletter:refresh-stats')->hourlyAt(20)->withoutOverlapping();

// Statistics (22.1, 22.13): the Redis counters reach the daily tables every hour (safe to repeat); the list of missing pages
// keeps the raw retention period (90 days by default), the daily figures stay for ever.
Schedule::command('stats:flush')->hourlyAt(5)->withoutOverlapping();
Schedule::command('stats:prune')->dailyAt('02:50')->withoutOverlapping();
