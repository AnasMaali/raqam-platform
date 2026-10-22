<?php

namespace App\Ads;

/**
 * Where an ad may send a visitor: an https address of a real host, nothing else. No http (the visitor would leave
 * our https site for a page anyone on the way can change), no javascript:/data:/file:, no user name or password inside
 * the address (https://bank.ps@evil.example looks like the bank), no spaces or control characters, no //host.
 */
final class AdTargetUrl
{
    public const MAX = 2048;

    public static function problem(?string $url): ?string
    {
        $url = (string) $url;
        if ($url === '') {
            return 'أدخل رابط المعلن.';
        }
        if (strlen($url) > self::MAX) {
            return 'الرابط أطول من المسموح (2048 حرفاً).';
        }
        if (preg_match('/[\x00-\x20\x7F]|\\\\/', $url)) {
            return 'الرابط فيه فراغات أو رموز غير مسموحة.';
        }
        if (! preg_match('~^https://~i', $url)) {
            return 'رابط المعلن يجب أن يبدأ بـ https://';
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return 'الرابط غير صحيح.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'الرابط لا يقبل اسم مستخدم أو كلمة مرور داخله.';
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return 'الرابط لا يقبل منفذاً غير المعتاد.';
        }
        $host = strtolower($parts['host']);
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/', $host)) {
            return 'اسم الموقع في الرابط غير صحيح.';
        }

        return null;
    }

    public static function isValid(?string $url): bool
    {
        return self::problem($url) === null;
    }
}
