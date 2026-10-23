<?php

namespace App\Panel;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use Illuminate\Support\Facades\DB;

/**
 * Raises an alert for the admins (20.3, 20.4, 20.10): a row in admin_notifications for each active admin (the bell),
 * and, when the alert has one, an e-mail to each of them. The same alert (same dedupe key) is raised at most once in
 * the window, an hour by default (20.11); the hour is measured with the database clock, and an advisory lock keeps two
 * alerts raised at the same moment from both deciding the hour is free.
 */
final class AdminAlerts
{
    public const CATEGORIES = [
        'security' => 'الأمان', 'content' => 'المحتوى', 'ads' => 'الإعلانات',
        'provider' => 'مصادر البيانات', 'system' => 'النظام', 'messages' => 'الرسائل',
    ];

    /**
     * @param  string|null  $actionPath  where the alert leads, inside the panel (e.g. "contact-messages/5")
     * @param  callable(): TemplatedEmail|null  $email  the e-mail, made once per admin
     * @return bool whether it was raised (false: the same alert was raised within the window)
     */
    public static function raise(string $category, string $dedupeKey, string $title, ?string $body = null, ?string $actionPath = null,
        ?callable $email = null, ?int $windowMinutes = null): bool
    {
        $window = $windowMinutes ?? (int) config('raqam.alerts.window_minutes');
        $title = mb_substr($title, 0, 200);
        $body = $body === null ? null : mb_substr($body, 0, 1000);
        $actionPath = $actionPath === null ? null : mb_substr(ltrim($actionPath, '/'), 0, 500);

        $raised = DB::transaction(function () use ($category, $dedupeKey, $title, $body, $actionPath, $window) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['alert:'.$dedupeKey]);
            $recent = DB::table('admin_notifications')->where('dedupe_key', $dedupeKey)
                ->where('created_at', '>', DB::raw("now() - make_interval(mins => {$window})"))->exists();
            if ($recent) {
                return false;
            }
            DB::insert(
                "INSERT INTO admin_notifications (user_id, category, title, body, action_path, dedupe_key)
                 SELECT u.id, ?, ?, ?, ?, ? FROM users u
                 WHERE u.user_type = 'staff' AND u.is_active AND u.deleted_at IS NULL",
                [$category, $title, $body, $actionPath, $dedupeKey],
            );

            return true;
        });

        if ($raised && $email !== null) {
            SystemMail::toAdmins($email);
        }

        return $raised;
    }

    /** How many alerts this admin has not read yet. */
    public static function unreadCount(int $userId): int
    {
        return DB::table('admin_notifications')->where('user_id', $userId)->whereNull('read_at')->count();
    }

    /** @return list<object> the newest alerts of this admin, unread first */
    public static function latest(int $userId, int $limit = 10): array
    {
        return DB::table('admin_notifications')->where('user_id', $userId)
            ->orderByRaw('read_at IS NOT NULL')->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'category', 'title', 'body', 'action_path', 'read_at', 'created_at'])->all();
    }

    /** Marks one alert (or all, without an id) of this admin as read; nobody else's. */
    public static function markRead(int $userId, ?int $id = null): int
    {
        return DB::table('admin_notifications')->where('user_id', $userId)->whereNull('read_at')
            ->when($id !== null, fn ($q) => $q->where('id', $id))
            ->update(['read_at' => DB::raw('now()')]);
    }
}
