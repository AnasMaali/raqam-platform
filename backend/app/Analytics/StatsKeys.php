<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Where the counters live between two transfers (22.1): Redis, on the queue connection (never emptied by cache:clear),
 * one set of keys per Palestine day, all of them gone after `raqam.stats.redis_seconds`. The day's random salt is
 * among them, so once it expires nobody can tell which address a fingerprint of that day was.
 */
final class StatsKeys
{
    public static function redis(): Connection
    {
        return Redis::connection((string) config('raqam.stats.redis_connection', 'default'));
    }

    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Hebron')->toDateString();
    }

    public static function day(string $day, string $name): string
    {
        return "stats:{$day}:{$name}";
    }

    public static function ttl(): int
    {
        return (int) config('raqam.stats.redis_seconds');
    }
}
