<?php

namespace App\Panel;

use App\Mail\TemplatedEmail;
use App\Support\RequestMemo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * The platform's own parts (QA-6, docs/qa/resilience.md): Redis, the queue, the scheduler and the search engine. Each
 * check says whether the part works, and an alert tells the admins when one stops: the bell and an e-mail sent right
 * away (never queued: the queue may be what stopped), the same alert at most once an hour (AdminAlerts).
 *
 * Who watches whom: the scheduler (system:watch, every minute) beats and looks at the queue; the queue worker looks at
 * the beat; every request finds out about Redis; `/up` looks at all of them, for a monitor outside the server, the
 * only one that still sees a machine where the scheduler and the worker both stopped.
 */
final class SystemWatch
{
    public const BEAT = 'system:scheduler-beat';

    /** Whether this request needs Redis at all (its cache, sessions or queue are there). */
    public static function usesRedis(): bool
    {
        return config('cache.default') === 'redis' || config('session.driver') === 'redis' || config('queue.default') === 'redis';
    }

    /** Whether Redis answers; asked once per request (a Redis that does not answer costs its timeout once). */
    public static function redisUp(): bool
    {
        return RequestMemo::remember('system.redis_up', function () {
            try {
                Redis::connection()->ping();

                return true;
            } catch (\Throwable) {
                Redis::purge();   // a connection that failed is not kept for the next caller

                return false;
            }
        });
    }

    /** The scheduler's beat (system:watch, every minute). */
    public static function beat(): void
    {
        Cache::forever(self::BEAT, now()->getTimestamp());
    }

    /** Minutes since the scheduler last beat when that is too long (never beat = late); null while it runs. */
    public static function schedulerLate(): ?int
    {
        $beat = Cache::get(self::BEAT);
        $minutes = $beat === null ? null : intdiv(max(0, now()->getTimestamp() - (int) $beat), 60);

        return $minutes !== null && $minutes < (int) config('raqam.alerts.scheduler_late_minutes') ? null : ($minutes ?? -1);
    }

    /** Minutes the oldest job has waited when that is too long (nobody takes the jobs); null otherwise. */
    public static function queueLate(): ?int
    {
        $queue = Queue::connection();
        if (! method_exists($queue, 'creationTimeOfOldestPendingJob')) {
            return null;
        }
        $created = $queue->creationTimeOfOldestPendingJob();
        if ($created === null) {
            return null;
        }
        $minutes = intdiv(max(0, now()->getTimestamp() - (int) $created), 60);

        return $minutes >= (int) config('raqam.alerts.queue_late_minutes') ? $minutes : null;
    }

    /** When the queue worker last looked at the scheduler (it lives for hours: a property of the process). */
    private static ?int $workerLooked = null;

    /**
     * The queue worker, between jobs: every few minutes it looks at the scheduler's beat. Not at once when it starts, so
     * a worker started a moment before the scheduler's first minute does not raise a false alarm.
     */
    public static function lookFromWorker(): void
    {
        $now = now()->getTimestamp();
        if (self::$workerLooked === null || $now - self::$workerLooked >= (int) config('raqam.alerts.scheduler_check_seconds')) {
            $first = self::$workerLooked === null;
            self::$workerLooked = $now;
            if (! $first && ($late = rescue(fn () => self::schedulerLate(), null)) !== null) {
                self::schedulerStopped($late);
            }
        }
    }

    /** @return array{database: bool, redis: bool, scheduler: bool, queue: bool} what works, for `/up` */
    public static function report(): array
    {
        $database = rescue(fn () => DB::select('select 1') !== [], false, false);
        $redis = ! self::usesRedis() || self::redisUp();
        // Without Redis neither the beat nor the jobs can be read: those two count as failing, and Redis is the one to fix.
        $scheduler = rescue(fn () => self::schedulerLate() === null, false, false);
        $queue = rescue(fn () => self::queueLate() === null, false, false);

        return ['database' => $database, 'redis' => $redis, 'scheduler' => $scheduler, 'queue' => $queue];
    }

    public static function redisDown(): void
    {
        self::alert('redis', 'Redis لا يستجيب',
            'الموقع يعرض الأخبار من قاعدة البيانات مباشرة، أما النماذج (التواصل، النشرة، المساعد) ولوحة التحكم فمتوقفة حتى يعود.',
            'تأكد أن خدمة Redis تعمل على السيرفر. يعود كل شيء وحده عندما تعود.');
    }

    public static function queueStopped(int $minutes): void
    {
        self::alert('queue', "الطابور متوقف: مهمة تنتظر منذ {$minutes} دقيقة",
            'الرسائل وتحديث البحث وأحجام الصور وتحديث الموقع بعد التعديل تنتظر.',
            'تأكد أن queue:work يعمل. المهام المنتظرة تُنفّذ عندما يعود.');
    }

    public static function schedulerStopped(int $minutes): void
    {
        self::alert('scheduler', $minutes < 0 ? 'المهام المجدولة لا تعمل' : "المهام المجدولة متوقفة منذ {$minutes} دقيقة",
            'النشر المجدول وجلب العملات والطقس وإحصائيات الزيارات متوقفة.',
            'تأكد أن schedule:run يعمل كل دقيقة. ما فات من النشر والإحصائيات يُستكمل عندما يعود.');
    }

    public static function searchDown(): void
    {
        self::alert('search', 'محرك البحث لا يستجيب',
            'البحث يعمل مؤقتاً من قاعدة البيانات (أبطأ وأقل دقة)، والتعديلات تنتظر حتى تصل إلى الفهرس.',
            'تأكد أن Meilisearch يعمل. يعود البحث إليه وحده، والفهرس يُصلح كل ليلة.');
    }

    private static function alert(string $part, string $title, string $effect, string $fix): void
    {
        try {
            $time = now('Asia/Hebron')->format('Y-m-d H:i');
            AdminAlerts::raise('system', 'down:'.$part, $title, $effect.' '.$fix, null,
                fn () => new TemplatedEmail('admin_service_down', ['الخدمة' => $title, 'الوقت' => $time, 'الأثر' => $effect, 'الحل' => AlertText::html($fix)->toHtml()]));
        } catch (\Throwable $e) {
            report($e);   // an alert about a failure must not become a failure of its own
        }
    }
}
