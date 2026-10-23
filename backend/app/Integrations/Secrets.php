<?php

namespace App\Integrations;

/**
 * Keeps the keys of outside services out of anything we store or show (provider_health, logs, messages).
 * OpenWeather takes its key in the address (appid=…), so an address must never be written down unscrubbed.
 */
final class Secrets
{
    public const MARK = '[hidden]';

    /** Config entries that hold a key for an outside service. */
    private const KEYS = ['raqam.youtube.key', 'raqam.weather.key', 'raqam.search.key', 'raqam.api.site_token',
        'raqam.newsletter.brevo.key', 'raqam.newsletter.brevo.webhook_token', 'raqam.turnstile.secret'];

    public static function scrub(string $text): string
    {
        foreach (self::KEYS as $setting) {
            $value = (string) config($setting);
            if (strlen($value) >= 4) {
                $text = str_replace([$value, rawurlencode($value)], self::MARK, $text);
            }
        }

        // Any key-like query parameter, whatever its value.
        return (string) preg_replace('/\b(appid|api_?key|key|token|access_key)=[^&\s"\'<>]*/i', '$1='.self::MARK, $text);
    }
}
