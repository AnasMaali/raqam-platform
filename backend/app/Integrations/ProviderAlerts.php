<?php

namespace App\Integrations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alerts about outside services that keep failing (decisions 11.3, 12.3, 20.4, 20.11).
 *
 * raise() leaves one notification per active admin in admin_notifications, never more than once an hour for
 * the same service (the bell of 20.10 reads them). current() is what the panel's home shows: every service
 * that has failed ALERT_AFTER times in a row and not answered since, read live from provider_health.
 */
final class ProviderAlerts
{
    /** What each service is called on screen. */
    public const NAMES = [
        'bank_of_israel' => 'مصدر العملات (بنك إسرائيل)',
        'exchangerate_api' => 'مصدر العملات (ExchangeRate-API)',
        'openweathermap' => 'مصدر الطقس (OpenWeather)',
        'youtube' => 'يوتيوب',
        'purelymail' => 'البريد (Purelymail)',
        'brevo' => 'النشرة (Brevo)',
    ];

    /** What visitors see while it is down. */
    private const KEEPS = [
        'currency' => 'الموقع يعرض آخر أسعار صحيحة.',
        'weather' => 'الموقع يعرض آخر طقس صحيح.',
    ];

    public static function name(string $provider): string
    {
        return self::NAMES[$provider] ?? $provider;
    }

    public static function raise(string $provider, int $failures, string $lastError): void
    {
        $key = 'provider:'.$provider;
        $service = (string) DB::table('data_providers')->where('code', $provider)->value('service');
        $title = self::name($provider).' فشل '.$failures.' مرات متتالية';
        $body = mb_substr(trim('آخر خطأ: '.$lastError.' '.(self::KEEPS[$service] ?? '')), 0, 1000);

        DB::transaction(function () use ($key, $title, $body) {
            // Two failures at the same moment must not both decide the hour is free.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
            DB::insert(
                "INSERT INTO admin_notifications (user_id, category, title, body, dedupe_key)
                 SELECT u.id, 'provider', ?, ?, ? FROM users u
                 WHERE u.user_type = 'staff' AND u.is_active AND u.deleted_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM admin_notifications n
                                   WHERE n.dedupe_key = ? AND n.created_at > now() - interval '1 hour')",
                [$title, $body, $key, $key],
            );
        });
    }

    /** @return list<string> one sentence per service that is failing now */
    public static function current(): array
    {
        $rows = DB::table('provider_health')->join('data_providers', 'data_providers.code', '=', 'provider_health.provider_code')
            ->where('provider_health.consecutive_failures', '>=', ProviderHealth::ALERT_AFTER)
            ->orderBy('provider_health.provider_code')
            ->get(['provider_health.*', 'data_providers.service']);

        return $rows->map(function (object $row) {
            $since = $row->last_success_at === null ? 'ولم ينجح أي اتصال بعد' : 'منذ آخر نجاح في '.self::time($row->last_success_at);

            return self::name($row->provider_code).' فشل '.$row->consecutive_failures.' مرات متتالية ('.$since.').'
                .($row->last_error ? ' آخر خطأ: '.$row->last_error : '')
                .(isset(self::KEEPS[$row->service]) ? ' '.self::KEEPS[$row->service] : '');
        })->values()->all();
    }

    public static function time(string $timestamp): string
    {
        return Carbon::parse($timestamp)->timezone('Asia/Hebron')->format('Y-m-d H:i');
    }
}
