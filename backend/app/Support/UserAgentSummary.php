<?php

namespace App\Support;

/** Browser and operating system names from a User-Agent header; the raw header is never shown or e-mailed. */
final class UserAgentSummary
{
    /** @return array{browser: string, os: string} */
    public static function of(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/'), str_contains($ua, 'EdgA/'), str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'متصفح غير معروف',
        };

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad'), str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux'), str_contains($ua, 'X11') => 'Linux',
            default => 'نظام غير معروف',
        };

        return ['browser' => $browser, 'os' => $os];
    }
}
