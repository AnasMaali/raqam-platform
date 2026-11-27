<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which machines may speak for the visitor (24.2). A request reaches PHP through the platform's own reverse proxy
 * (Caddy, TRUSTED_LOCAL_PROXIES) and, in production, through Cloudflare before it (TRUST_CLOUDFLARE=1, the ranges in
 * config/cloudflare.php). Only those may name the visitor in X-Forwarded-For or CF-Connecting-IP; anything else the
 * request says about who sent it was written by the sender and proves nothing. Never `*`: a range wider than a local
 * network is refused (ProductionChecks).
 */
final class ProxyAddresses
{
    /** The widest range accepted as "local": a docker network is a /16 at most. */
    private const NARROWEST_LOCAL_V4 = 16;

    private const NARROWEST_LOCAL_V6 = 48;

    /** Cloudflare's published ranges are /13 to /22 (IPv4) and /29 to /32 (IPv6); anything wider is not theirs. */
    private const NARROWEST_CLOUDFLARE_V4 = 8;

    private const NARROWEST_CLOUDFLARE_V6 = 24;

    /** @return list<string> the platform's own reverse proxy (Caddy): addresses or ranges */
    public static function local(): array
    {
        return self::split(config('raqam.proxies.local'));
    }

    public static function trustCloudflare(): bool
    {
        return (bool) config('raqam.proxies.trust_cloudflare');
    }

    /** @return list<string> */
    public static function cloudflare(): array
    {
        return [...(array) config('cloudflare.ipv4', []), ...(array) config('cloudflare.ipv6', [])];
    }

    /** @return list<string> every machine whose forwarding headers are believed */
    public static function trusted(): array
    {
        return [...self::local(), ...(self::trustCloudflare() ? self::cloudflare() : [])];
    }

    public static function isLocal(?string $ip): bool
    {
        return $ip !== null && self::local() !== [] && IpUtils::checkIp($ip, self::local());
    }

    public static function isCloudflare(?string $ip): bool
    {
        return $ip !== null && IpUtils::checkIp($ip, self::cloudflare());
    }

    /**
     * An address as one form: an IPv4 address written as IPv6 (::ffff:a.b.c.d, what a dual-stack socket reports for an
     * IPv4 client) is that IPv4 address, so it meets the IPv4 ranges and is counted as the same visitor. Null when it is
     * not an address at all.
     */
    public static function plain(?string $ip): ?string
    {
        $packed = $ip === null ? false : @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }

    /**
     * What is wrong with a list of ranges: each must be an address or a range of the right family, and none wider than
     * the limit given (a `*`, `0.0.0.0/0` or `::/0` would let anyone name any visitor).
     *
     * @param  list<string>  $ranges
     * @return list<string>
     */
    public static function problems(array $ranges, int $narrowestV4, int $narrowestV6): array
    {
        $problems = [];
        foreach ($ranges as $range) {
            [$address, $bits] = array_pad(explode('/', $range, 2), 2, null);
            $packed = @inet_pton($address);
            $max = $packed === false ? 0 : strlen($packed) * 8;
            $prefix = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($packed === false || $prefix < 0 || $prefix > $max) {
                $problems[] = "\"{$range}\" is not an address or a range.";
            } elseif ($prefix < ($max === 32 ? $narrowestV4 : $narrowestV6)) {
                $problems[] = "\"{$range}\" is too wide.";
            }
        }

        return $problems;
    }

    /** @return list<string> what is wrong with the settings (ProductionChecks names the setting, these the entries) */
    public static function settingProblems(): array
    {
        $problems = [];
        if (preg_match('~(^|,)\s*\*~', (string) config('raqam.proxies.local'))) {
            $problems[] = 'TRUSTED_LOCAL_PROXIES must never be "*".';
        }
        foreach (self::problems(self::local(), self::NARROWEST_LOCAL_V4, self::NARROWEST_LOCAL_V6) as $problem) {
            $problems[] = 'TRUSTED_LOCAL_PROXIES: '.$problem;
        }
        if (self::trustCloudflare()) {
            if (config('cloudflare.ipv4', []) === [] || config('cloudflare.ipv6', []) === []) {
                $problems[] = 'TRUST_CLOUDFLARE is on but config/cloudflare.php lists no ranges.';
            }
            foreach (self::cloudflareProblems(self::cloudflare()) as $problem) {
                $problems[] = 'config/cloudflare.php: '.$problem;
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $ranges
     * @return list<string>
     */
    public static function cloudflareProblems(array $ranges): array
    {
        return self::problems($ranges, self::NARROWEST_CLOUDFLARE_V4, self::NARROWEST_CLOUDFLARE_V6);
    }

    /** @return list<string> */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $part) => $part !== ''));
    }
}
