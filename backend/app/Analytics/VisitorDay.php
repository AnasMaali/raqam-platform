<?php

namespace App\Analytics;

use App\Support\TrackingHash;

/**
 * Who a visitor is for one Palestine day (22.2): an HMAC of their network (an IPv4 address, or an IPv6 /64) and browser
 * under a random salt made for that day. The salt lives only in Redis and expires two days later; the address itself is
 * never kept, not even in a key. Tomorrow the same person is somebody else, so nobody can follow them from day to day.
 */
final class VisitorDay
{
    public static function fingerprint(string $day, ?string $ip, ?string $agent): string
    {
        return substr(hash_hmac('sha256', (TrackingHash::network($ip) ?? 'unknown').'|'.(string) $agent, self::salt($day)), 0, 32);
    }

    /** The network alone (address or IPv6 /64), under the same daily salt: the key of a ceiling, never the address. */
    public static function network(string $day, ?string $ip): string
    {
        return substr(hash_hmac('sha256', 'network|'.(TrackingHash::network($ip) ?? 'unknown'), self::salt($day)), 0, 32);
    }

    public static function salt(string $day): string
    {
        $redis = StatsKeys::redis();
        $key = StatsKeys::day($day, 'salt');
        $salt = $redis->get($key);
        if (! is_string($salt) || strlen($salt) !== 64) {
            $redis->set($key, bin2hex(random_bytes(32)), 'EX', StatsKeys::ttl(), 'NX');
            $salt = (string) $redis->get($key);
        }

        return $salt;
    }
}
