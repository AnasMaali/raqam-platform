<?php

namespace App\Analytics;

/**
 * Today's counters as they stand in Redis, before the hourly transfer writes them (22.1). The screens show the larger of
 * these and the stored row, as the transfer itself will, so "today" is never an hour behind.
 */
final class LiveToday
{
    /** @return array{date: string, page_views: int, visitors: int, posts: array<int, int>, videos: array<int, int>, sources: array<string, int>, devices: array<string, int>, events: array<string, int>} */
    public static function counters(): array
    {
        $day = StatsKeys::today();
        $redis = StatsKeys::redis();
        $hash = fn (string $name) => array_map('intval', (array) $redis->hgetall(StatsKeys::day($day, $name)));
        $ids = fn (array $counts) => array_filter($counts, fn ($n, $id) => preg_match('/^[1-9][0-9]{0,17}$/', (string) $id) === 1, ARRAY_FILTER_USE_BOTH);

        return [
            'date' => $day,
            'page_views' => (int) $redis->hget(StatsKeys::day($day, 'site'), 'page_views'),
            'visitors' => (int) $redis->scard(StatsKeys::day($day, 'visitors')),
            'posts' => $ids($hash('posts')),
            'videos' => $ids($hash('videos')),
            'sources' => $hash('sources'),
            'devices' => $hash('devices'),
            'events' => $hash('events'),
        ];
    }
}
