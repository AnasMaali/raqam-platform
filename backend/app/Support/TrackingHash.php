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
        return hash_hmac('sha256', 'visitor|'.(self::network($ip) ?? 'unknown'), (string) config('app.key'));
    }

    /**
     * Who a visitor is for limits and counters: an IPv4 address as it is, an IPv6 address by its /64. One home or
     * phone line gets a whole /64 and can take a new address from it at will, so a limit per IPv6 address would be
     * no limit at all. An IPv4 address written as IPv6 (::ffff:a.b.c.d) is that IPv4 address.
     */
    public static function network(?string $ip): ?string
    {
        $packed = $ip === null ? false : @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 4) {
            return inet_ntop($packed);
        }
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    public static function ip(): ?string
    {
        return self::of(request()->ip());
    }
}
