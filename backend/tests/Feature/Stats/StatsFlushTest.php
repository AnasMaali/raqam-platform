<?php

use App\Analytics\StatsFlusher;
use App\Analytics\StatsKeys;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['raqam.api.site_token' => STATS_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example']);
    clearStatsKeys();
});

afterEach(fn () => clearStatsKeys());

function flushedPost(): int
{
    return insertPost(publishedPostValues(['published_at' => now()->subDays(3)]));
}

function publicVideo(): int
{
    $video = insertYoutubeVideo(substr(md5((string) random_int(1, PHP_INT_MAX)), 0, 11));
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'حلقات', 'slug' => 'list-'.$video, 'is_visible' => true]);
    DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => 1]);

    return $video;
}

/** @return array<string, mixed> the daily rows of a date, table by table */
function dailyRows(string $day): array
{
    $pairs = fn (string $table, string $key, string $column) => DB::table($table)->where('stat_date', $day)->orderBy($key)
        ->pluck($column, $key)->map(fn ($n) => (int) $n)->all();
    $site = DB::table('site_stats_daily')->where('stat_date', $day)->first();

    return [
        'site' => $site ? [(int) $site->page_views, (int) $site->unique_visitors] : null,
        'posts' => $pairs('post_views_daily', 'post_id', 'views'),
        'sources' => $pairs('traffic_sources_daily', 'source_code', 'visits'),
        'devices' => $pairs('device_stats_daily', 'device_type', 'visits'),
        'events' => $pairs('event_stats_daily', 'event_code', 'count'),
        'videos' => $pairs('video_plays_daily', 'video_id', 'plays'),
    ];
}

test('the hourly transfer writes every counter of the day into its daily table', function () {
    $post = flushedPost();
    $video = publicVideo();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post, 'referrer' => 'https://www.google.com/']);
    siteHit(['type' => 'view', 'path' => '/'], ip: '198.51.100.9', agent: DESKTOP_AGENT);
    siteHit(['type' => 'view', 'path' => '/a'], ip: '198.51.100.9', agent: DESKTOP_AGENT);
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click']);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play', 'video_id' => $video]);

    $this->artisan('stats:flush')->assertSuccessful();

    expect(dailyRows(StatsKeys::today()))->toBe([
        'site' => [3, 2],
        'posts' => [$post => 1],
        'sources' => ['direct' => 1, 'google' => 1],
        'devices' => ['desktop' => 1, 'mobile' => 1],
        'events' => ['share_click' => 1, 'video_play' => 1],
        'videos' => [$video => 1],
    ]);
});

test('running the transfer again, or after it was cut off, counts nothing twice', function () {
    $post = flushedPost();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);
    app(StatsFlusher::class)->flush();
    app(StatsFlusher::class)->flush();
    siteHit(['type' => 'view', 'path' => '/a']);
    app(StatsFlusher::class)->flush();
    app(StatsFlusher::class)->flush();

    expect(dailyRows(StatsKeys::today())['site'])->toBe([2, 1])->and(dailyRows(StatsKeys::today())['posts'])->toBe([$post => 1]);
});

test('a Redis that lost its counters never lowers a figure already stored', function () {
    $post = flushedPost();
    foreach (['/a', '/b', '/c'] as $i => $path) {
        siteHit(['type' => 'view', 'path' => $path, 'post_id' => $post], ip: "198.51.100.{$i}");
    }
    app(StatsFlusher::class)->flush();
    clearStatsKeys();   // Redis restarted without its data
    siteHit(['type' => 'view', 'path' => '/d', 'post_id' => $post]);
    app(StatsFlusher::class)->flush();

    expect(dailyRows(StatsKeys::today())['site'])->toBe([3, 3])->and(dailyRows(StatsKeys::today())['posts'])->toBe([$post => 3]);
});

test('a transfer is one transaction: when one row cannot be written, none is', function () {
    $post = flushedPost();
    siteHit(['type' => 'view', 'path' => '/']);
    // More views than the column holds: that row fails, and the page views written before it must go too.
    StatsKeys::redis()->hset(StatsKeys::day(StatsKeys::today(), 'posts'), (string) $post, '3000000000');

    expect(fn () => app(StatsFlusher::class)->flush())->toThrow(QueryException::class);
    expect(DB::table('site_stats_daily')->count())->toBe(0);
});

test('yesterday is transferred too, so the last hour of a day is never lost', function () {
    $this->travelTo(now('Asia/Hebron')->subDay()->setTime(23, 50));
    siteHit(['type' => 'view', 'path' => '/late']);
    $yesterday = StatsKeys::today();
    $this->travelBack();
    $this->travelTo(now('Asia/Hebron')->setTime(0, 5));

    $this->artisan('stats:flush')->assertSuccessful();

    expect(dailyRows($yesterday)['site'])->toBe([1, 1]);
});

test('rows of a post or video that was deleted meanwhile, or of an unknown counter, are left out', function () {
    $post = flushedPost();
    $video = publicVideo();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);
    siteHit(['type' => 'event', 'path' => '/v', 'event' => 'video_play', 'video_id' => $video]);
    DB::table('posts')->where('id', $post)->delete();
    DB::table('videos')->where('id', $video)->delete();
    $day = StatsKeys::today();
    StatsKeys::redis()->hset(StatsKeys::day($day, 'sources'), 'myspace', 4);
    StatsKeys::redis()->hset(StatsKeys::day($day, 'events'), 'like', 4);
    StatsKeys::redis()->hset(StatsKeys::day($day, 'devices'), 'fridge', 4);
    StatsKeys::redis()->hset(StatsKeys::day($day, 'posts'), 'x;drop', 4);

    app(StatsFlusher::class)->flush();

    expect(dailyRows($day))->toMatchArray(['site' => [1, 1], 'posts' => [], 'videos' => [], 'sources' => ['direct' => 1], 'devices' => ['mobile' => 1], 'events' => ['video_play' => 1]]);   // the play happened; only the video's row goes
});

test('unique visitors never exceed page views, even when the two counters disagree', function () {
    $day = StatsKeys::today();
    StatsKeys::redis()->hset(StatsKeys::day($day, 'site'), 'page_views', 2);
    StatsKeys::redis()->sadd(StatsKeys::day($day, 'visitors'), 'a', 'b', 'c');

    app(StatsFlusher::class)->flush();

    expect(dailyRows($day)['site'])->toBe([2, 2]);
});

test('the post totals follow the daily rows after each transfer', function () {
    $post = flushedPost();
    DB::table('post_views_daily')->insert(['post_id' => $post, 'stat_date' => now('Asia/Hebron')->subDays(2)->toDateString(), 'views' => 40]);
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);

    app(StatsFlusher::class)->flush();

    expect((int) DB::table('post_view_totals')->where('post_id', $post)->value('total_views'))->toBe(41);
});

test('the transfer runs every hour and the raw list of missing pages is pruned every night', function () {
    $events = collect(app(Schedule::class)->events());
    $flush = $events->filter(fn ($e) => str_contains($e->command, 'stats:flush'));
    $prune = $events->filter(fn ($e) => str_contains($e->command, 'stats:prune'));

    expect($flush)->toHaveCount(1)->and($flush->first()->expression)->toBe('5 * * * *')
        ->and($prune)->toHaveCount(1)->and($prune->first()->expression)->toBe('50 2 * * *');
});

test('missing pages go after the raw retention period (90 days by default); the daily figures stay', function () {
    DB::table('not_found_paths')->insert([
        ['path' => '/old', 'first_seen_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(91), 'resolved_at' => null],
        ['path' => '/recent', 'first_seen_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(89), 'resolved_at' => null],
        ['path' => '/resolved-long-ago', 'first_seen_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(1), 'resolved_at' => now()->subDays(91)],
        ['path' => '/resolved-lately', 'first_seen_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(1), 'resolved_at' => now()->subDays(10)],
    ]);
    DB::table('site_stats_daily')->insert(['stat_date' => '2020-01-01', 'page_views' => 10, 'unique_visitors' => 5]);

    $this->artisan('stats:prune')->assertSuccessful();

    expect(DB::table('not_found_paths')->orderBy('path')->pluck('path')->all())->toBe(['/recent', '/resolved-lately'])
        ->and(DB::table('site_stats_daily')->where('stat_date', '2020-01-01')->exists())->toBeTrue();
});

test('the raw retention period is the setting analytics.raw_retention_days', function () {
    DB::table('settings')->where('key', 'analytics.raw_retention_days')->update(['value' => '30']);
    DB::table('not_found_paths')->insert([
        ['path' => '/a', 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(31)],
        ['path' => '/b', 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now()->subDays(29)],
    ]);

    $this->artisan('stats:prune')->assertSuccessful();

    expect(DB::table('not_found_paths')->pluck('path')->all())->toBe(['/b']);
});
