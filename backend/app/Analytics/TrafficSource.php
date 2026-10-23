<?php

namespace App\Analytics;

/**
 * Where a visit came from (22.6), one of the codes of traffic_sources. A campaign tag in the page address
 * (utm_source=…) wins, because the apps that share links most here (WhatsApp, Instagram, TikTok, mail) usually send no
 * referrer at all; then the referrer's host; no referrer is a direct visit. A referrer from the site itself is not a
 * source (it is the same visit going on), so it reads as direct when it does open a visit.
 */
final class TrafficSource
{
    /** @var array<string, list<string>> source => hosts (a host matches itself and its subdomains) */
    private const HOSTS = [
        'facebook' => ['facebook.com', 'fb.com', 'fb.me', 'messenger.com', 'facebook.net'],
        'instagram' => ['instagram.com'],
        'whatsapp' => ['whatsapp.com', 'whatsapp.net', 'wa.me'],
        'tiktok' => ['tiktok.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'x' => ['x.com', 'twitter.com', 't.co'],
        'bing' => ['bing.com'],
    ];

    /** @var array<string, string> Android apps that send android-app://<package> as the referrer */
    private const APPS = [
        'com.google.android.googlequicksearchbox' => 'google', 'com.google.android.gm' => 'other',
        'com.facebook.katana' => 'facebook', 'com.facebook.orca' => 'facebook', 'com.facebook.lite' => 'facebook',
        'com.instagram.android' => 'instagram', 'com.whatsapp' => 'whatsapp', 'com.whatsapp.w4b' => 'whatsapp',
        'com.zhiliaoapp.musically' => 'tiktok', 'com.ss.android.ugc.trill' => 'tiktok',
        'com.google.android.youtube' => 'youtube', 'com.twitter.android' => 'x',
    ];

    /** @var array<string, string> utm_source values => source */
    private const TAGS = [
        'google' => 'google', 'bing' => 'bing', 'facebook' => 'facebook', 'fb' => 'facebook', 'instagram' => 'instagram',
        'ig' => 'instagram', 'whatsapp' => 'whatsapp', 'wa' => 'whatsapp', 'tiktok' => 'tiktok', 'youtube' => 'youtube',
        'yt' => 'youtube', 'x' => 'x', 'twitter' => 'x', 'newsletter' => 'newsletter', 'brevo' => 'newsletter', 'email' => 'newsletter',
    ];

    public static function of(?string $referrer, ?string $path, ?string $siteHost = null): string
    {
        if (($tag = self::tag($path)) !== null) {
            return $tag;
        }
        $referrer = trim((string) $referrer);
        if ($referrer === '') {
            return 'direct';
        }
        if (preg_match('~^android-app://([a-z0-9_.]+)~i', $referrer, $m) === 1) {
            return self::APPS[strtolower($m[1])] ?? 'other';
        }
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if ($host === '' || ! preg_match('~^https?://~i', $referrer)) {
            return 'direct';
        }
        $host = rtrim($host, '.');
        if ($siteHost !== null && $siteHost !== '' && self::within($host, strtolower($siteHost))) {
            return 'direct';
        }
        // google.com, google.ps, google.co.il, news.google.com … but not notgoogle.com.
        if (preg_match('~(^|\.)google(\.[a-z]{2,3}){1,2}$~', $host) === 1) {
            return 'google';
        }
        foreach (self::HOSTS as $source => $hosts) {
            foreach ($hosts as $known) {
                if (self::within($host, $known)) {
                    return $source;
                }
            }
        }

        return 'other';
    }

    private static function tag(?string $path): ?string
    {
        $query = (string) parse_url((string) $path, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }
        parse_str($query, $params);
        $value = $params['utm_source'] ?? null;

        return is_string($value) ? (self::TAGS[strtolower(trim($value))] ?? null) : null;
    }

    private static function within(string $host, string $domain): bool
    {
        $domain = preg_replace('~^www\.~', '', $domain);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
