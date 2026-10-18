<?php

namespace App\Support;

use RuntimeException;

/** HMAC-SHA256 of an IP address or a device cookie. The raw value is never stored (decisions 22.17, 24.14). */
final class TrackingHash
{
    public static function of(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $key = config('raqam.tracking_key')
            ?: throw new RuntimeException('TRACKING_HASH_KEY is not set; IP addresses cannot be recorded safely.');

        return hash_hmac('sha256', $value, $key);
    }

    /**
     * The key of a rate-limit counter for one visitor. Counters live in Redis under their key, so the address
     * itself must not be in it; and since a counter is gone within the hour, it is keyed with the application key
     * (always present) rather than the tracking key that stored records use.
     */
    public static function visitor(?string $ip): string
    {
        return hash_hmac('sha256', 'visitor|'.($ip ?? 'unknown'), (string) config('app.key'));
    }

    public static function ip(): ?string
    {
        return self::of(request()->ip());
    }
}
