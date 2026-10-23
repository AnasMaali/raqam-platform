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

    /**
     * E-mail (20.9, 21.15), from the e-mail log: the last message that finished decides the state (sent = working,
     * failed after its tries = failing); nothing sent yet = unknown.
     *
     * @return array{name: string, state: string, key: string, lines: list<string>}
     */
    private static function email(): array
    {
        $mailer = (string) config('mail.default');
        $real = ! in_array($mailer, ['log', 'array'], true);
        $password = $mailer === 'smtp' ? (filled(config('mail.mailers.smtp.password')) ? 'مضبوطة' : 'غير مضبوطة') : null;
        $key = $password === null ? '—' : 'كلمة المرور: '.$password;
        $lines = ['طريقة الإرسال: '.$mailer.($real ? '' : ' (الرسائل تُكتب في السجل ولا تُرسَل)')];
        if (filled(config('mail.from.address'))) {
            $lines[] = 'المرسِل الافتراضي: '.config('mail.from.address');
        }
        if (! $real) {
            return ['name' => 'البريد', 'state' => self::OFF, 'key' => $key, 'lines' => $lines];
        }

        $last = DB::table('email_log')->whereIn('status', ['sent', 'failed'])->orderByDesc('id')->first(['status', 'error']);
        $lastSent = DB::table('email_log')->where('status', 'sent')->max('sent_at');
        $failedDay = DB::table('email_log')->where('status', 'failed')->where('queued_at', '>', DB::raw("now() - interval '1 day'"))->count();
        $waiting = DB::table('email_log')->where('status', 'queued')->where('queued_at', '<', DB::raw("now() - interval '10 minutes'"))->count();

        $lines[] = 'آخر إرسال ناجح: '.($lastSent ? ProviderAlerts::time($lastSent) : '—');
        $lines[] = 'فشل آخر 24 ساعة: '.$failedDay;
        if ($waiting > 0) {
            $lines[] = 'رسائل بالانتظار أكثر من 10 دقائق: '.$waiting.' (هل يعمل الطابور؟)';
        }
        if ($last?->status === 'failed') {
            $lines[] = 'آخر خطأ: '.$last->error;
        }

        $state = match ($last?->status) {
            'sent' => self::OK,
            'failed' => self::FAILING,
            default => self::UNKNOWN,
        };

        return ['name' => 'البريد', 'state' => $state, 'key' => $key, 'lines' => $lines];
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
