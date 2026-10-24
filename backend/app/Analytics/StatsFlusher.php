<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves the Redis counters of today and yesterday (Palestine days) to the daily tables (22.1), once an hour.
 *
 * Redis holds each day's running totals, not what is new since the last transfer, and every row is written as the
 * larger of what the table holds and what Redis holds. So a transfer that runs twice, or is cut off and run again,
 * writes the same numbers (nothing counted twice), and a Redis that lost its data (a restart without its file) never
 * lowers a figure already stored. Everything of one transfer is one transaction: all of it lands or none of it.
 * Rows of a post, a video, a source or an event that no longer exists are left out.
 */
final class StatsFlusher
{
    public const VERSION = 'stats:version';

    /** @return array<string, int> rows written per table */
    public function flush(?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now('Asia/Hebron'))->setTimezone('Asia/Hebron')->startOfDay();
        $days = [$today->subDay()->toDateString(), $today->toDateString()];

        $written = ['site' => 0, 'posts' => 0, 'sources' => 0, 'devices' => 0, 'events' => 0, 'videos' => 0];
        $snapshot = array_map(fn (string $day) => $this->read($day), array_combine($days, $days));

        DB::transaction(function () use ($snapshot, &$written) {
            foreach ($snapshot as $day => $counts) {
                if ($counts['page_views'] > 0) {
                    DB::statement(
                        'INSERT INTO site_stats_daily (stat_date, page_views, unique_visitors) VALUES (?::date, ?::bigint, least(?::bigint, ?::bigint))
                         ON CONFLICT (stat_date) DO UPDATE SET
                           page_views = greatest(site_stats_daily.page_views, EXCLUDED.page_views),
                           unique_visitors = least(greatest(site_stats_daily.unique_visitors, EXCLUDED.unique_visitors),
                                                   greatest(site_stats_daily.page_views, EXCLUDED.page_views))',
                        [$day, $counts['page_views'], $counts['visitors'], $counts['page_views']],
                    );
                    $written['site']++;
                }
                $written['posts'] += $this->upsert('post_views_daily', ['post_id', 'stat_date'], 'views', 'posts', 'id', $day, $counts['posts'], 'bigint');
                $written['videos'] += $this->upsert('video_plays_daily', ['video_id', 'stat_date'], 'plays', 'videos', 'id', $day, $counts['videos'], 'bigint');
                $written['sources'] += $this->upsert('traffic_sources_daily', ['source_code', 'stat_date'], 'visits', 'traffic_sources', 'code', $day, $counts['sources'], 'varchar');
                $written['devices'] += $this->upsert('device_stats_daily', ['device_type', 'stat_date'], 'visits', null, null, $day, $counts['devices'], 'varchar');
                $written['events'] += $this->upsert('event_stats_daily', ['event_code', 'stat_date'], 'count', 'event_types', 'code', $day, $counts['events'], 'varchar');
            }
        });

        // The totals shown with a post (when the admin shows them, 22.12) follow the daily rows.
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals');
        Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 0) + 1);

        return $written;
    }

    /** @return array{page_views: int, visitors: int, posts: array<string, int>, videos: array<string, int>, sources: array<string, int>, devices: array<string, int>, events: array<string, int>} */
    private function read(string $day): array
    {
        $redis = StatsKeys::redis();
        $hash = fn (string $name) => array_map('intval', (array) $redis->hgetall(StatsKeys::day($day, $name)));

        return [
            'page_views' => (int) $redis->hget(StatsKeys::day($day, 'site'), 'page_views'),
            'visitors' => (int) $redis->scard(StatsKeys::day($day, 'visitors')),
            'posts' => $hash('posts'),
            'videos' => $hash('videos'),
            'sources' => $hash('sources'),
            'devices' => array_intersect_key($hash('devices'), array_flip(['mobile', 'tablet', 'desktop'])),
            'events' => $hash('events'),
        ];
    }

    /**
     * One statement per table and day: the counts as a VALUES list, joined to the table they belong to (so a deleted
     * post is skipped), each row the larger of the stored and the counted number.
     *
     * @param  array{0: string, 1: string}  $key  the key column and the date column
     * @param  array<string, int>  $counts
     */
    private function upsert(string $table, array $key, string $column, ?string $parent, ?string $parentKey, string $day, array $counts, string $type): int
    {
        $counts = array_filter($counts, fn (int $n) => $n > 0);
        if ($counts === []) {
            return 0;
        }
        if ($type === 'bigint') {
            $counts = array_filter($counts, fn ($n, $id) => preg_match('/^[1-9][0-9]{0,17}$/', (string) $id) === 1, ARRAY_FILTER_USE_BOTH);
        }
        $values = implode(', ', array_fill(0, count($counts), "(?::{$type}, ?::bigint)"));
        $bindings = [];
        foreach ($counts as $id => $n) {
            array_push($bindings, (string) $id, $n);
        }
        [$keyColumn, $dateColumn] = $key;
        $join = $parent === null ? '' : "JOIN {$parent} p ON p.{$parentKey} = v.k";

        return DB::affectingStatement(
            "INSERT INTO {$table} ({$keyColumn}, {$dateColumn}, {$column})
             SELECT v.k, ?::date, v.n FROM (VALUES {$values}) AS v(k, n) {$join}
             ON CONFLICT ({$keyColumn}, {$dateColumn}) DO UPDATE SET {$column} = greatest({$table}.{$column}, EXCLUDED.{$column})",
            [$day, ...$bindings],
        );
    }
}
