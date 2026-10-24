<?php

namespace App\Analytics;

use App\Models\Post;
use App\Models\Video;
use App\Support\BotAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Counts what the site reports (22.1–22.10). Page views, visitors, sources, devices, post views, interactions and video
 * plays go to Redis counters of the Palestine day and reach PostgreSQL once an hour (StatsFlusher); a page that was not
 * found goes straight to not_found_paths (a running total, not a daily figure, and rare).
 *
 * Nothing is counted for a robot (22.3). Against a visitor who inflates the numbers: the same page within
 * `reload_seconds` counts once; a post counts once per visitor per day; a video once per visitor per day; the same
 * interaction once in `event_gap_seconds`; and beyond `views_per_visitor_day` views, `events_per_visitor_hour`
 * interactions or `not_found_per_visitor_day` missing pages nothing more of that visitor counts that day; and one network
 * brings at most `visitors_per_network_day` visitors a day (a browser name is easy to change). A refused hit
 * is silent: the caller never learns whether it counted.
 *
 * A visit (for sources and devices) opens with a visitor's first view after `visit_minutes` of quiet; its source is the
 * referrer of that view.
 */
final class StatsCounter
{
    public const VIEW = 'view';

    public const EVENT = 'event';

    public const NOT_FOUND = 'not_found';

    /** @param  array{type: string, path: string, referrer?: ?string, post_id?: ?int, video_id?: ?int, event?: ?string}  $hit */
    public function record(array $hit, ?string $ip, ?string $agent): bool
    {
        if (BotAgent::isBot($agent) || self::isPreview($hit['path'])) {
            return false;
        }
        $day = StatsKeys::today();
        $visitor = VisitorDay::fingerprint($day, $ip, $agent);
        if (! $this->knownOnNetwork($day, $visitor, $ip)) {
            return false;
        }

        return match ($hit['type']) {
            self::VIEW => $this->view($day, $visitor, $hit, $agent),
            self::EVENT => $this->event($day, $visitor, (string) $hit['event'], $hit['video_id'] ?? null),
            self::NOT_FOUND => $this->notFound($day, $visitor, $hit['path']),
            default => false,
        };
    }

    /** @param  array{path: string, referrer?: ?string, post_id?: ?int}  $hit */
    private function view(string $day, string $visitor, array $hit, ?string $agent): bool
    {
        $redis = StatsKeys::redis();
        $ttl = StatsKeys::ttl();
        if (! $this->under(StatsKeys::day($day, "views-of:{$visitor}"), (int) config('raqam.stats.views_per_visitor_day'), $ttl)
            || ! $this->once(StatsKeys::day($day, 'page:'.$visitor.':'.sha1(self::bare($hit['path']))), (int) config('raqam.stats.reload_seconds'))) {
            return false;
        }

        $site = StatsKeys::day($day, 'site');
        $visitors = StatsKeys::day($day, 'visitors');
        $redis->hincrby($site, 'page_views', 1);
        $redis->sadd($visitors, $visitor);
        $redis->expire($site, $ttl);
        $redis->expire($visitors, $ttl);

        $siteHost = (string) parse_url((string) config('raqam.site_url'), PHP_URL_HOST);
        $visit = StatsKeys::day($day, "visit:{$visitor}");
        $quiet = max(1, 60 * (int) config('raqam.stats.visit_minutes'));
        if ($redis->set($visit, 1, 'EX', $quiet, 'NX')) {
            $this->add($day, 'sources', TrafficSource::of($hit['referrer'] ?? null, $hit['path'], $siteHost));
            $this->add($day, 'devices', DeviceType::of($agent));
        } else {
            $redis->expire($visit, $quiet);
        }

        $post = $hit['post_id'] ?? null;
        if ($post !== null && self::viewable((int) $post) && $this->firstToday($day, "read:{$visitor}", (string) $post)) {
            $this->add($day, 'posts', (string) $post);
        }

        return true;
    }

    private function event(string $day, string $visitor, string $code, ?int $video): bool
    {
        if (! in_array($code, self::eventCodes(), true)) {
            return false;
        }
        $hour = now('Asia/Hebron')->format('YmdH');
        $gap = StatsKeys::day($day, "event:{$visitor}:{$code}:".($video ?? ''));
        if (! $this->under("stats:events-of:{$hour}:{$visitor}", (int) config('raqam.stats.events_per_visitor_hour'), 3600)
            || ! $this->once($gap, (int) config('raqam.stats.event_gap_seconds'))) {
            return false;
        }

        if ($code === 'video_play') {
            // A play needs a video the site shows, and counts once per visitor per video and day (like a post).
            if ($video === null || ! self::publicVideo($video) || ! $this->firstToday($day, "played:{$visitor}", (string) $video)) {
                return false;
            }
            $this->add($day, 'videos', (string) $video);
        }
        $this->add($day, 'events', $code);

        return true;
    }

    private function notFound(string $day, string $visitor, string $path): bool
    {
        $path = self::bare($path);
        if (mb_strlen($path) > 500
            || ! $this->under(StatsKeys::day($day, "missing-of:{$visitor}"), (int) config('raqam.stats.not_found_per_visitor_day'), StatsKeys::ttl())
            || ! $this->firstToday($day, "missing:{$visitor}", sha1($path))) {
            return false;
        }

        // The database clock for both times, and never earlier than the first sighting (a CHECK compares them).
        DB::statement(
            'INSERT INTO not_found_paths (path) VALUES (?)
             ON CONFLICT (path) DO UPDATE SET hits = not_found_paths.hits + 1, last_seen_at = greatest(now(), not_found_paths.first_seen_at)',
            [$path],
        );

        return true;
    }

    /**
     * The visitor is a network and a browser, so one address could invent visitors by changing its browser name. A
     * network brings at most `visitors_per_network_day` visitors a day (a shared line or a phone company's address
     * carries many real people, hence a high ceiling); a new one beyond that is not counted, the ones already seen are.
     */
    private function knownOnNetwork(string $day, string $visitor, ?string $ip): bool
    {
        $key = StatsKeys::day($day, 'network:'.VisitorDay::network($day, $ip));
        $redis = StatsKeys::redis();
        if ((int) $redis->sadd($key, $visitor) === 0) {
            return true;
        }
        $redis->expire($key, StatsKeys::ttl());
        if ((int) $redis->scard($key) > (int) config('raqam.stats.visitors_per_network_day')) {
            $redis->srem($key, $visitor);

            return false;
        }

        return true;
    }

    private function add(string $day, string $counter, string $field): void
    {
        $key = StatsKeys::day($day, $counter);
        StatsKeys::redis()->hincrby($key, $field, 1);
        StatsKeys::redis()->expire($key, StatsKeys::ttl());
    }

    /** True the first time within $seconds (no gap at all when it is 0). */
    private function once(string $key, int $seconds): bool
    {
        return $seconds <= 0 || (bool) StatsKeys::redis()->set($key, 1, 'EX', $seconds, 'NX');
    }

    /** Adds a member to a visitor's set of the day; true when it was not there yet. */
    private function firstToday(string $day, string $set, string $member): bool
    {
        $key = StatsKeys::day($day, $set);
        $added = (int) StatsKeys::redis()->sadd($key, $member) === 1;
        StatsKeys::redis()->expire($key, StatsKeys::ttl());

        return $added;
    }

    /** One more for a ceiling counter; false once it is past the ceiling. */
    private function under(string $key, int $ceiling, int $ttl): bool
    {
        $count = (int) StatsKeys::redis()->incr($key);
        if ($count === 1) {
            StatsKeys::redis()->expire($key, $ttl);
        }

        return $count <= $ceiling;
    }

    /** The path without its query and fragment: /news/1/x?utm_source=y and /news/1/x are the same page. */
    public static function bare(string $path): string
    {
        return (string) preg_replace('~[?#].*$~s', '', $path);
    }

    /** Admin previews are pages of the panel's own server (/preview/…), never a visit to the site. */
    public static function isPreview(string $path): bool
    {
        return preg_match('~^/preview(/|$)~', self::bare($path)) === 1;
    }

    /** A yes is remembered for a minute (one query per post a minute, not per view); a no is asked again each time. */
    private static function viewable(int $post): bool
    {
        return self::rememberYes("stats:viewable:{$post}", fn () => Post::query()->viewable()->whereKey($post)->exists());
    }

    private static function publicVideo(int $video): bool
    {
        return self::rememberYes("stats:video:{$video}", fn () => Video::query()->public()->whereKey($video)->exists());
    }

    private static function rememberYes(string $key, \Closure $check): bool
    {
        if (Cache::get($key) === true) {
            return true;
        }
        $yes = (bool) $check();
        if ($yes) {
            Cache::put($key, true, 60);
        }

        return $yes;
    }

    /** @return list<string> */
    public static function eventCodes(): array
    {
        return Cache::remember('stats:event-codes', 3600, fn () => DB::table('event_types')->orderBy('code')->pluck('code')->all());
    }
}
