<?php

namespace App\Panel;

use App\Integrations\Secrets;
use App\Jobs\SendSystemEmail;
use App\Mail\TemplatedEmail;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Queue\Events\JobFailed;

/**
 * A job of the queue that gave up, or a scheduled task that failed (20.4): the bell and an e-mail for the admins, the
 * same job or task at most once an hour (20.11). When the job that failed is an e-mail, only the bell rings: the mail
 * is what is broken. Never throws: an alert about a failure must not become a failure of its own.
 */
final class SystemFailures
{
    public static function job(JobFailed $event): void
    {
        $class = $event->job->resolveName();
        $name = class_basename($class);
        $email = $class === SendSystemEmail::class ? null : self::email($name, $event->exception);

        self::raise('job:'.$class, 'فشلت المهمة «'.$name.'»', $event->exception, $email);
    }

    public static function taskFailed(ScheduledTaskFailed $event): void
    {
        $name = self::taskName($event->task);
        self::raise('task:'.$name, 'فشلت المهمة المجدولة «'.$name.'»', $event->exception, self::email($name, $event->exception));
    }

    public static function taskFinished(ScheduledTaskFinished $event): void
    {
        if (($code = $event->task->exitCode) === null || $code === 0) {
            return;
        }
        $name = self::taskName($event->task);
        $error = new \RuntimeException('انتهت برمز خروج '.$code.'.');
        self::raise('task:'.$name, 'فشلت المهمة المجدولة «'.$name.'»', $error, self::email($name, $error));
    }

    /** "php artisan currency:fetch" → "currency:fetch" */
    public static function taskName(ScheduledEvent $task): string
    {
        $command = (string) ($task->description ?: $task->command);
        if (preg_match("/artisan'?\s+'?([^'\s]+(?:\s+--?[^'\s]+)*)/", $command, $m) === 1) {
            return $m[1];
        }

        return mb_substr(trim($command), 0, 120) ?: 'مهمة مجدولة';
    }

    /** @return callable(): TemplatedEmail */
    private static function email(string $name, \Throwable $e): callable
    {
        $time = now('Asia/Hebron')->format('Y-m-d H:i');

        return fn () => new TemplatedEmail('admin_job_failed', ['المهمة' => $name, 'الوقت' => $time, 'الخطأ' => self::reason($e)]);
    }

    private static function raise(string $key, string $title, \Throwable $e, ?callable $email): void
    {
        try {
            AdminAlerts::raise('system', mb_substr($key, 0, 120), $title, 'الخطأ: '.self::reason($e), null, $email);
        } catch (\Throwable $inner) {
            report($inner);
        }
    }

    private static function reason(\Throwable $e): string
    {
        return mb_substr(Secrets::scrub(trim($e->getMessage()) ?: class_basename($e)), 0, 300);
    }
}
