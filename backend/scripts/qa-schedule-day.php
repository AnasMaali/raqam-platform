<?php

/*
 * One whole day of the scheduler on the QA environment (QA-6, docs/qa/resilience.md): every scheduled task, in the
 * order and at the minutes the schedule gives them on that day (Palestine time), each one run as the scheduler runs it
 * (its own process: php artisan <task>); the tasks of other days (weekly) once after it. Then the day is judged: every task ended well, no job failed, nothing was
 * written as an error, no alert of a failed task.
 *
 *   cd backend && APP_ENV=qa php scripts/qa-schedule-day.php [YYYY-MM-DD, today] [--every-minute=60]
 *
 * The tasks of every minute (publishing, the search net, the weather, the watch) run every 60th minute of the day
 * (--every-minute=N for another step); all the others at each of their own minutes. The clock of the tasks themselves
 * is the real one; only the choice of what is due is made on the day's clock.
 *
 * Only raqam_qa. The outside services must be the local stand-ins (e2e/qa/stand-in.mjs) and the mail the log:
 * the script refuses to start otherwise, so a day of tasks never sends a real e-mail or reaches a real service.
 */

use App\Panel\SystemFailures;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$stop = function (string $why): never {
    fwrite(STDERR, "qa-schedule-day: {$why}\n");
    exit(1);
};
if (DB::connection()->getDatabaseName() !== 'raqam_qa') {
    $stop('only against raqam_qa (APP_ENV=qa).');
}
if (! in_array(config('mail.default'), ['log', 'array'], true)) {
    $stop('the mail must be the log (MAIL_MAILER=log): a day of tasks must not send real e-mail.');
}
$local = fn (?string $url) => $url === null || $url === '' || preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#', $url) === 1;
foreach (['raqam.currency.boi_base', 'raqam.weather.api_base', 'raqam.youtube.api_base', 'raqam.newsletter.brevo.api_base', 'raqam.search.host', 'raqam.site_webhook.url'] as $key) {
    if (! $local(config($key))) {
        $stop("{$key} is not a local address: point it at the stand-in (e2e/qa/stand-in.mjs).");
    }
}
foreach (['raqam.currency.boi_base' => true, 'raqam.weather.api_base' => filled(config('raqam.weather.key')), 'raqam.youtube.api_base' => filled(config('raqam.youtube.key')),
    'raqam.newsletter.brevo.api_base' => filled(config('raqam.newsletter.brevo.key'))] as $key => $used) {
    if ($used && blank(config($key))) {
        $stop("{$key} is empty while its service is on: the task would reach the real service.");
    }
}
if (filled(config('raqam.indexnow.key'))) {
    $stop('IndexNow is on (INDEXNOW_KEY): it would tell the real search engines.');
}

$day = Carbon::parse(($argv[1] ?? '') !== '' && ! str_starts_with($argv[1], '--') ? $argv[1] : 'today', 'Asia/Hebron')->startOfDay();
$step = 60;
foreach ($argv as $arg) {
    if (preg_match('/^--every-minute=(\d+)$/', $arg, $m)) {
        $step = max(1, (int) $m[1]);
    }
}

// The schedule as `schedule:run` builds it at that minute: a time window (between()) is fixed when the schedule is
// built, so it is built again for every minute, as each run of the scheduler does.
$scheduleAt = function () use ($app): Schedule {
    $app->forgetInstance(Schedule::class);
    Facade::clearResolvedInstance(Schedule::class);
    $schedule = $app->make(Schedule::class);
    require base_path('routes/console.php');

    return $schedule;
};
$everyMinute = fn ($event) => $event->expression === '* * * * *';

// The day's timetable: what is due at each minute, as the scheduler would decide it.
$timetable = [];
for ($minute = 0; $minute < 1440; $minute++) {
    $at = $day->copy()->addMinutes($minute);
    Carbon::setTestNow($at);
    foreach ($scheduleAt()->events() as $event) {
        if (! $event->isDue($app) || ! $event->filtersPass($app)) {
            continue;
        }
        if ($everyMinute($event) && $minute % $step !== 0) {
            continue;
        }
        $timetable[] = [$at->copy(), SystemFailures::taskName($event)];
    }
}
Carbon::setTestNow();

// The tasks of other days (the weekly ones): run once after the day, so every task of the schedule has run.
$ofTheDay = array_unique(array_column($timetable, 1));
foreach ($scheduleAt()->events() as $event) {
    $task = SystemFailures::taskName($event);
    if (! in_array($task, $ofTheDay, true)) {
        $timetable[] = [null, $task];
        $ofTheDay[] = $task;
    }
}

$before = [
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'task_alerts' => DB::table('admin_notifications')->where('dedupe_key', 'like', 'task:%')->count(),
    'job_alerts' => DB::table('admin_notifications')->where('dedupe_key', 'like', 'job:%')->count(),
];
$log = storage_path('logs/laravel.log');
$logStart = is_file($log) ? filesize($log) : 0;

printf("The schedule of %s (Palestine): %d runs of %d tasks (the every-minute ones every %d minutes; \"once\": a task of another day).\n\n",
    $day->toDateString(), count($timetable), count(array_unique(array_column($timetable, 1))), $step);

$failed = [];
$totals = [];
$started = microtime(true);
foreach ($timetable as [$at, $task]) {
    $t = microtime(true);
    $result = Process::path(base_path())->timeout(900)->run(array_merge([PHP_BINARY, 'artisan'], explode(' ', $task)));
    $seconds = microtime(true) - $t;
    $totals[$task] = ($totals[$task] ?? [0, 0.0]);
    $totals[$task][0]++;
    $totals[$task][1] += $seconds;
    $last = trim((string) collect(explode("\n", trim($result->output()."\n".$result->errorOutput())))->filter()->last());
    printf("%s  %-36s %s %5.1fs  %s\n", $at?->format('H:i') ?? 'once ', $task, $result->successful() ? 'ok  ' : 'FAIL', $seconds, mb_substr($last, 0, 90));
    if (! $result->successful()) {
        $failed[] = ($at?->format('H:i') ?? 'once')." {$task}: exit {$result->exitCode()}";
    }
}

$after = [
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'task_alerts' => DB::table('admin_notifications')->where('dedupe_key', 'like', 'task:%')->count(),
    'job_alerts' => DB::table('admin_notifications')->where('dedupe_key', 'like', 'job:%')->count(),
];
$errors = [];
if (is_file($log)) {
    $handle = fopen($log, 'r');
    fseek($handle, $logStart);
    while (($line = fgets($handle)) !== false) {
        if (preg_match('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,160})/', $line, $m)) {
            $errors[] = $m[2];
        }
    }
    fclose($handle);
}

echo "\nPer task: runs, total seconds\n";
ksort($totals);
foreach ($totals as $task => [$runs, $seconds]) {
    printf("  %-36s %4d  %7.1f\n", $task, $runs, $seconds);
}
printf("\n%d runs in %.0f s. Failed: %d. New failed jobs: %d. New alerts of a failed task or job: %d. Errors written to the log: %d.\n",
    count($timetable), microtime(true) - $started, count($failed), $after['failed_jobs'] - $before['failed_jobs'],
    ($after['task_alerts'] - $before['task_alerts']) + ($after['job_alerts'] - $before['job_alerts']), count($errors));
foreach ([...$failed, ...array_slice($errors, 0, 20)] as $line) {
    echo "  - {$line}\n";
}

exit($failed === [] && $errors === [] && $after == $before ? 0 : 1);
