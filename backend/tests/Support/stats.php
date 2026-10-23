<?php

use App\Analytics\StatsKeys;
use Illuminate\Testing\TestResponse;

/** Helpers for the statistics tests: a report from the site's server, and a clean set of counters. */
const STATS_SITE_TOKEN = 'site-token-stats-123';

const DESKTOP_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

const TABLET_AGENT = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

/** Removes this process's statistics keys by name (never a whole Redis database). */
function clearStatsKeys(): void
{
    $redis = StatsKeys::redis();
    $prefix = (string) config('database.redis.options.prefix');
    // KEYS takes the connection's prefix on its own, and answers with it.
    foreach ((array) $redis->keys('stats:*') as $key) {
        $redis->del(str_starts_with((string) $key, $prefix) ? substr((string) $key, strlen($prefix)) : (string) $key);
    }
}

/** A report as the site's server sends it: its token, the visitor's address and browser. */
function siteHit(array $body, string $ip = '203.0.113.7', string $agent = PERSON_AGENT, ?string $token = STATS_SITE_TOKEN): TestResponse
{
    $headers = ['X-Raqam-Visitor-Ip' => $ip, 'X-Raqam-Visitor-Ua' => $agent, 'User-Agent' => 'raqam-site/1.0 (server)'];
    if ($token !== null) {
        $headers['X-Raqam-Site-Token'] = $token;
    }

    return test()->withHeaders($headers)->postJson('/api/v1/stats/hits', $body);
}

/** @return array<string, int> */
function statsHash(string $name, ?string $day = null): array
{
    return array_map('intval', (array) StatsKeys::redis()->hgetall(StatsKeys::day($day ?? StatsKeys::today(), $name)));
}

function pageViewsToday(): int
{
    return (int) StatsKeys::redis()->hget(StatsKeys::day(StatsKeys::today(), 'site'), 'page_views');
}

function visitorsToday(): int
{
    return (int) StatsKeys::redis()->scard(StatsKeys::day(StatsKeys::today(), 'visitors'));
}
