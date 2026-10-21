<?php

namespace App\Panel;

use App\Integrations\DataProviders;
use App\Integrations\ProviderAlerts;
use App\Search\MeiliClient;
use App\Video\YouTubeQuota;
use Illuminate\Support\Facades\DB;

/**
 * The state of every outside connection, for the panel's home (decision 21.15): currencies, weather, YouTube,
 * search and e-mail. A key is only ever reported as set or not set: no character of it is shown, not even the last four.
 */
final class ConnectionStatus
{
    public const OK = 'ok';

    public const FAILING = 'failing';

    public const OFF = 'off';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<array{name: string, state: string, key: string, lines: list<string>}>
     */
    public static function all(bool $probeSearch = true): array
    {
        return [self::currency(), self::weather(), self::youtube(), self::search($probeSearch), self::email()];
    }

    /** @return array{name: string, state: string, key: string, lines: list<string>} */
    private static function currency(): array
    {
        $code = DataProviders::active('currency');

        return self::fromHealth('العملات', $code, 'لا يحتاج مفتاحاً', $code === null ? ['لا يوجد مصدر مفعّل.'] : ['المصدر: '.ProviderAlerts::name($code)]);
    }

    /** @return array{name: string, state: string, key: string, lines: list<string>} */
    private static function weather(): array
    {
        if (blank(config('raqam.weather.key'))) {
            return ['name' => 'الطقس', 'state' => self::OFF, 'key' => 'غير مضبوط', 'lines' => ['لا يُجلب طقس، ويبقى آخر طقس محفوظ. أضف OPENWEATHER_API_KEY إلى ملف البيئة.']];
        }

        return self::fromHealth('الطقس', 'openweathermap', 'مضبوط', ['المصدر: OpenWeather (المجاني)']);
    }

    /** @return array{name: string, state: string, key: string, lines: list<string>} */
    private static function youtube(): array
    {
        if (blank(config('raqam.youtube.key'))) {
            return ['name' => 'يوتيوب', 'state' => self::OFF, 'key' => 'غير مضبوط', 'lines' => ['الفيديو يُضاف بإدخال العنوان يدوياً.']];
        }

        return self::fromHealth('يوتيوب', 'youtube', 'مضبوط', ['حصة اليوم المستهلكة: '.app(YouTubeQuota::class)->used().' من '.config('raqam.youtube.quota_budget').' وحدة']);
    }

    /** @return array{name: string, state: string, key: string, lines: list<string>} */
    private static function search(bool $probe): array
    {
        if (! MeiliClient::configured()) {
            return ['name' => 'البحث', 'state' => self::OFF, 'key' => 'غير مضبوط', 'lines' => ['محرك البحث مطفأ؛ البحث يعمل من قاعدة البيانات.']];
        }
        if (! $probe) {
            return ['name' => 'البحث', 'state' => self::UNKNOWN, 'key' => 'مضبوط', 'lines' => []];
        }
        $up = MeiliClient::fromConfig()->isReachable();

        return ['name' => 'البحث', 'state' => $up ? self::OK : self::FAILING, 'key' => 'مضبوط',
            'lines' => [$up ? 'Meilisearch يعمل.' : 'Meilisearch لا يستجيب؛ البحث يعمل مؤقتاً من قاعدة البيانات.']];
    }

    /** @return array{name: string, state: string, key: string, lines: list<string>} */
    private static function email(): array
    {
        $mailer = (string) config('mail.default');
        $real = ! in_array($mailer, ['log', 'array'], true);
        $password = $mailer === 'smtp' ? (filled(config('mail.mailers.smtp.password')) ? 'مضبوطة' : 'غير مضبوطة') : null;

        return [
            'name' => 'البريد', 'state' => $real ? self::UNKNOWN : self::OFF, 'key' => $password === null ? '—' : 'كلمة المرور: '.$password,
            'lines' => array_values(array_filter([
                'طريقة الإرسال: '.$mailer.($real ? '' : ' (الرسائل تُكتب في السجل ولا تُرسَل)'),
                filled(config('mail.from.address')) ? 'المرسِل: '.config('mail.from.address') : null,
                $real ? 'لا يُتابَع نجاح الإرسال هنا بعد (مرحلة التنبيهات).' : null,
            ])),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{name: string, state: string, key: string, lines: list<string>}
     */
    private static function fromHealth(string $name, ?string $provider, string $key, array $lines): array
    {
        $health = $provider === null ? null : DB::table('provider_health')->where('provider_code', $provider)->first();
        if ($health === null) {
            return ['name' => $name, 'state' => self::UNKNOWN, 'key' => $key, 'lines' => $lines];
        }

        $state = match (true) {
            $health->consecutive_failures > 0 => self::FAILING,
            $health->last_success_at !== null => self::OK,
            default => self::UNKNOWN,
        };

        $lines[] = 'آخر نجاح: '.($health->last_success_at ? ProviderAlerts::time($health->last_success_at) : '—');
        if ($health->consecutive_failures > 0) {
            $lines[] = 'فشل متتالٍ: '.$health->consecutive_failures.' (آخره '.ProviderAlerts::time($health->last_failure_at).')';
            $lines[] = 'آخر خطأ: '.$health->last_error;
        }

        return ['name' => $name, 'state' => $state, 'key' => $key, 'lines' => $lines];
    }
}
