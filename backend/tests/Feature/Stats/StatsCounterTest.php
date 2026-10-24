<?php

use App\Analytics\DeviceType;
use App\Analytics\StatsKeys;
use App\Analytics\TrafficSource;
use App\Analytics\VisitorDay;
use App\Content\PostPreview;
use App\Models\Post;
use App\Support\TrackingHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['raqam.api.site_token' => STATS_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example']);
    clearStatsKeys();
});

afterEach(fn () => clearStatsKeys());

function livePost(): int
{
    return insertPost(publishedPostValues(['published_at' => now()->subHour()]));
}

test('a page view from the site counts the view, the visitor, the visit source and device, and the post', function () {
    $post = livePost();

    siteHit(['type' => 'view', 'path' => "/news/{$post}/خبر", 'referrer' => 'https://www.google.com/', 'post_id' => $post])
        ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

    expect(pageViewsToday())->toBe(1)->and(visitorsToday())->toBe(1)
        ->and(statsHash('sources'))->toBe(['google' => 1])
        ->and(statsHash('devices'))->toBe(['mobile' => 1])
        ->and(statsHash('posts'))->toBe([(string) $post => 1]);
});

test('only the site counts: no token, a wrong token or no visitor address is answered alike and counts nothing', function () {
    siteHit(['type' => 'view', 'path' => '/'], token: null)->assertNoContent();
    siteHit(['type' => 'view', 'path' => '/'], token: 'wrong')->assertNoContent();
    siteHit(['type' => 'view', 'path' => '/'], ip: 'not-an-address')->assertNoContent();
    config(['raqam.api.site_token' => '']);
    siteHit(['type' => 'view', 'path' => '/'], token: '')->assertNoContent();

    expect(pageViewsToday())->toBe(0)->and(visitorsToday())->toBe(0);
});

test('robots and search engines are not counted (22.3)', function () {
    foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'facebookexternalhit/1.1', 'WhatsApp/2.23.20.0', 'curl/8.4.0', ''] as $agent) {
        siteHit(['type' => 'view', 'path' => '/'], agent: $agent)->assertNoContent();
    }

    expect(pageViewsToday())->toBe(0);
});

test('the endpoint takes JSON only, sets no cookie and opens no door to other sites', function () {
    $this->withHeaders(['X-Raqam-Site-Token' => STATS_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7', 'X-Raqam-Visitor-Ua' => PERSON_AGENT])
        ->post('/api/v1/stats/hits', ['type' => 'view', 'path' => '/'])->assertStatus(415);

    $response = $this->withHeaders(['Origin' => 'https://evil.example', 'X-Raqam-Site-Token' => STATS_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7', 'X-Raqam-Visitor-Ua' => PERSON_AGENT])
        ->postJson('/api/v1/stats/hits', ['type' => 'view', 'path' => '/']);
    $response->assertNoContent()->assertHeaderMissing('Set-Cookie')->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/stats/hits')->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('a malformed report is refused with the reason', function (array $body) {
    siteHit($body)->assertStatus(422);
})->with([
    'no type' => [['path' => '/']],
    'unknown type' => [['type' => 'click', 'path' => '/']],
    'no path' => [['type' => 'view']],
    'another host' => [['type' => 'view', 'path' => '//evil.example/x']],
    'not a path' => [['type' => 'view', 'path' => 'https://evil.example/']],
    'a space' => [['type' => 'view', 'path' => '/a b']],
    'unknown event' => [['type' => 'event', 'path' => '/', 'event' => 'like']],
    'event without its name' => [['type' => 'event', 'path' => '/']],
    'post with an event' => [['type' => 'event', 'path' => '/', 'event' => 'share_click', 'post_id' => 1]],
    'video with a view' => [['type' => 'view', 'path' => '/', 'video_id' => 1]],
    'post id too large' => [['type' => 'view', 'path' => '/', 'post_id' => 9999999999999999999]],
    'path too long' => [['type' => 'view', 'path' => '/'.str_repeat('a', 2000)]],
]);

test('the same page again within the reload gap counts once; another page counts', function () {
    siteHit(['type' => 'view', 'path' => '/'])->assertNoContent();
    siteHit(['type' => 'view', 'path' => '/'])->assertNoContent();
    siteHit(['type' => 'view', 'path' => '/?utm_source=x'])->assertNoContent();   // the same page with a tag
    siteHit(['type' => 'view', 'path' => '/about'])->assertNoContent();

    expect(pageViewsToday())->toBe(2);
});

test('the same page after the reload gap counts again', function () {
    config(['raqam.stats.reload_seconds' => 1]);
    siteHit(['type' => 'view', 'path' => '/'])->assertNoContent();
    usleep(1_100_000);
    siteHit(['type' => 'view', 'path' => '/'])->assertNoContent();

    expect(pageViewsToday())->toBe(2)->and(visitorsToday())->toBe(1);
});

test('a post counts once per visitor and day; another visitor counts again', function () {
    $post = livePost();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/a", 'post_id' => $post]);
    siteHit(['type' => 'view', 'path' => "/news/{$post}/a?ref=1", 'post_id' => $post]);
    siteHit(['type' => 'view', 'path' => "/news/{$post}/b", 'post_id' => $post]);   // another address of the same post
    siteHit(['type' => 'view', 'path' => "/news/{$post}/a", 'post_id' => $post], ip: '198.51.100.9');

    expect(statsHash('posts'))->toBe([(string) $post => 2])->and(pageViewsToday())->toBe(3);
});

test('a post that is not published (a draft, the trash, a future date) gets no views; the page view still counts', function () {
    $draft = insertPost();
    $future = insertPost(publishedPostValues(['published_at' => now()->addDay()]));
    $trashed = insertPost(publishedPostValues(['deleted_at' => now()]));
    foreach ([$draft, $future, $trashed] as $i => $id) {
        siteHit(['type' => 'view', 'path' => "/news/{$id}/x{$i}", 'post_id' => $id]);
    }
    siteHit(['type' => 'view', 'path' => '/news/999999/x', 'post_id' => 999999]);

    expect(statsHash('posts'))->toBe([])->and(pageViewsToday())->toBe(4);
});

test('an archived post is still read at its address and counts', function () {
    $post = insertPost(publishedPostValues(['status' => 'archived', 'published_at' => now()->subDay()]));
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);

    expect(statsHash('posts'))->toBe([(string) $post => 1]);
});

test('a visit opens with the first view after quiet: later views of it add no source or device', function () {
    siteHit(['type' => 'view', 'path' => '/', 'referrer' => 'https://l.facebook.com/l.php?u=x']);
    siteHit(['type' => 'view', 'path' => '/a', 'referrer' => 'https://raqam.example/']);
    siteHit(['type' => 'view', 'path' => '/b', 'referrer' => 'https://www.google.com/']);
    siteHit(['type' => 'view', 'path' => '/'], ip: '198.51.100.9', agent: DESKTOP_AGENT);
    siteHit(['type' => 'view', 'path' => '/'], ip: '198.51.100.10', agent: TABLET_AGENT);

    expect(statsHash('sources'))->toBe(['facebook' => 1, 'direct' => 2])
        ->and(statsHash('devices'))->toEqualCanonicalizing(['mobile' => 1, 'desktop' => 1, 'tablet' => 1])
        ->and(pageViewsToday())->toBe(5)->and(visitorsToday())->toBe(3);
});

test('after the quiet time the same visitor opens a new visit', function () {
    config(['raqam.stats.visit_minutes' => 0]);   // the shortest quiet: one second
    siteHit(['type' => 'view', 'path' => '/', 'referrer' => 'https://www.instagram.com/']);
    usleep(1_100_000);
    siteHit(['type' => 'view', 'path' => '/a', 'referrer' => 'https://www.tiktok.com/']);

    expect(statsHash('sources'))->toBe(['instagram' => 1, 'tiktok' => 1])->and(visitorsToday())->toBe(1);
});

test('the sources: campaign tag first, then the referrer host, then direct (22.6)', function (?string $referrer, string $path, string $source) {
    expect(TrafficSource::of($referrer, $path, 'raqam.example'))->toBe($source);
})->with([
    'google search' => ['https://www.google.com/', '/', 'google'],
    'google palestine' => ['https://www.google.ps/search?q=x', '/', 'google'],
    'google news' => ['https://news.google.com/articles/x', '/', 'google'],
    'not google' => ['https://notgoogle.com/', '/', 'other'],
    'facebook link shim' => ['https://lm.facebook.com/l.php?u=x', '/', 'facebook'],
    'facebook mobile' => ['https://m.facebook.com/', '/', 'facebook'],
    'facebook app' => ['android-app://com.facebook.katana/', '/', 'facebook'],
    'instagram' => ['https://l.instagram.com/?u=x', '/', 'instagram'],
    'whatsapp web' => ['https://web.whatsapp.com/', '/', 'whatsapp'],
    'whatsapp app' => ['android-app://com.whatsapp/', '/', 'whatsapp'],
    'wa.me' => ['https://wa.me/970599000000', '/', 'whatsapp'],
    'tiktok' => ['https://www.tiktok.com/@raqam', '/', 'tiktok'],
    'youtube' => ['https://m.youtube.com/watch?v=x', '/', 'youtube'],
    'x' => ['https://t.co/abc', '/', 'x'],
    'bing' => ['https://www.bing.com/search?q=x', '/', 'bing'],
    'another site' => ['https://example.org/page', '/', 'other'],
    'no referrer' => [null, '/', 'direct'],
    'empty referrer' => ['', '/', 'direct'],
    'the site itself' => ['https://raqam.example/news', '/', 'direct'],
    'a subdomain of the site' => ['https://www.raqam.example/', '/', 'direct'],
    'not a web address' => ['javascript:alert(1)', '/', 'direct'],
    'tag wins' => ['https://www.google.com/', '/news?utm_source=whatsapp', 'whatsapp'],
    'tag without referrer' => [null, '/?utm_source=ig&utm_medium=story', 'instagram'],
    'newsletter tag' => [null, '/news/1/x?utm_source=newsletter', 'newsletter'],
    'unknown tag' => ['https://www.bing.com/', '/?utm_source=partner', 'bing'],
]);

test('the device from the browser (22.7)', function (string $agent, string $device) {
    expect(DeviceType::of($agent))->toBe($device);
})->with([
    'android phone' => [PERSON_AGENT, 'mobile'],
    'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'mobile'],
    'android tablet' => [TABLET_AGENT, 'tablet'],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', 'tablet'],
    'windows' => [DESKTOP_AGENT, 'desktop'],
    'mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'desktop'],
]);

test('one visitor is one network and browser: an IPv6 /64 is one visitor, two browsers behind one address are two', function () {
    siteHit(['type' => 'view', 'path' => '/'], ip: '2001:db8:aa:bb::1');
    siteHit(['type' => 'view', 'path' => '/'], ip: '2001:db8:aa:bb:ffff::2');
    siteHit(['type' => 'view', 'path' => '/a'], ip: '203.0.113.50');
    siteHit(['type' => 'view', 'path' => '/a'], ip: '203.0.113.50', agent: DESKTOP_AGENT);

    expect(visitorsToday())->toBe(3)->and(pageViewsToday())->toBe(3);
});

test('beyond its daily ceiling nothing more of a visitor counts; others still do', function () {
    config(['raqam.stats.views_per_visitor_day' => 3]);
    $post = livePost();
    foreach (['/a', '/b', '/c', '/d', '/e'] as $path) {
        siteHit(['type' => 'view', 'path' => $path]);
    }
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);
    siteHit(['type' => 'view', 'path' => '/a'], ip: '198.51.100.9');

    expect(pageViewsToday())->toBe(4)->and(statsHash('posts'))->toBe([]);
});

test('the visitor of a day is a salted HMAC: the address is nowhere in Redis, and tomorrow it is somebody else', function () {
    $post = livePost();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post, 'referrer' => 'https://www.google.com/'], ip: '198.51.100.23');
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click'], ip: '198.51.100.23');
    siteHit(['type' => 'not_found', 'path' => '/missing-page'], ip: '198.51.100.23');

    $redis = StatsKeys::redis();
    $dump = '';
    foreach ((array) $redis->keys('stats:*') as $key) {
        $name = substr((string) $key, strlen((string) config('database.redis.options.prefix')));
        $dump .= $key."\n".json_encode(match ($redis->type($name)) {
            Redis::REDIS_HASH => $redis->hgetall($name), Redis::REDIS_SET => $redis->smembers($name), default => $redis->get($name),
        })."\n";
    }
    foreach (['198.51.100.23', TrackingHash::of('198.51.100.23'), TrackingHash::visitor('198.51.100.23'), hash('sha256', '198.51.100.23'), sha1('198.51.100.23'), md5('198.51.100.23')] as $form) {
        expect($dump)->not->toContain($form);
    }

    $salt = StatsKeys::day(StatsKeys::today(), 'salt');
    expect($redis->ttl($salt))->toBeGreaterThan(0)->toBeLessThanOrEqual(172800)
        ->and(VisitorDay::fingerprint('2026-10-24', '198.51.100.23', PERSON_AGENT))->not->toBe(VisitorDay::fingerprint('2026-10-25', '198.51.100.23', PERSON_AGENT))
        ->and(VisitorDay::fingerprint('2026-10-24', '198.51.100.23', PERSON_AGENT))->toBe(VisitorDay::fingerprint('2026-10-24', '198.51.100.23', PERSON_AGENT));
});

test('every counter of the day expires in Redis within two days', function () {
    $post = livePost();
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'converter_use']);

    $redis = StatsKeys::redis();
    $keys = (array) $redis->keys('stats:*');
    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        $ttl = $redis->ttl(substr((string) $key, strlen((string) config('database.redis.options.prefix'))));
        expect($ttl)->toBeGreaterThan(0, "{$key} never expires")->toBeLessThanOrEqual(172800);
    }
});

test('an interaction counts (22.8); the same one again within the gap counts once', function () {
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click'])->assertNoContent();
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click'])->assertNoContent();
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'converter_use'])->assertNoContent();
    siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click'], ip: '198.51.100.9')->assertNoContent();

    expect(statsHash('events'))->toBe(['share_click' => 2, 'converter_use' => 1]);
});

test('a video play needs a video the site shows, and counts once per visitor, video and day', function () {
    $video = insertYoutubeVideo('dQw4w9WgXcQ');
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'حلقات', 'slug' => 'episodes', 'is_visible' => true]);
    DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => 1]);
    $hidden = insertYoutubeVideo('aaaaaaaaaaa');

    config(['raqam.stats.event_gap_seconds' => 0]);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play', 'video_id' => $video]);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play', 'video_id' => $video]);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play', 'video_id' => $hidden]);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play']);
    siteHit(['type' => 'event', 'path' => '/videos', 'event' => 'video_play', 'video_id' => $video], ip: '198.51.100.9');

    expect(statsHash('videos'))->toBe([(string) $video => 2])->and(statsHash('events'))->toBe(['video_play' => 2]);
});

test('beyond its hourly ceiling of interactions nothing more of a visitor counts', function () {
    config(['raqam.stats.events_per_visitor_hour' => 2, 'raqam.stats.event_gap_seconds' => 0]);
    foreach (range(1, 4) as $_) {
        siteHit(['type' => 'event', 'path' => '/', 'event' => 'share_click']);
    }

    expect(statsHash('events'))->toBe(['share_click' => 2]);
});

test('a page not found is recorded without its query, once per visitor and day, with the database clock (22.10)', function () {
    siteHit(['type' => 'not_found', 'path' => '/old-news/12?fbclid=abc', 'referrer' => 'https://www.facebook.com/'])->assertNoContent();
    siteHit(['type' => 'not_found', 'path' => '/old-news/12'])->assertNoContent();
    siteHit(['type' => 'not_found', 'path' => '/old-news/12#top'], ip: '198.51.100.9')->assertNoContent();

    $row = DB::table('not_found_paths')->first();
    expect(DB::table('not_found_paths')->count())->toBe(1)
        ->and($row->path)->toBe('/old-news/12')->and((int) $row->hits)->toBe(2)
        ->and(DB::selectOne('select last_seen_at >= first_seen_at as ok from not_found_paths')->ok)->toBeTrue()
        ->and(pageViewsToday())->toBe(0);
});

test('a missing page keeps its resolution when it is reached again, and robots are not recorded', function () {
    DB::table('not_found_paths')->insert(['path' => '/gone', 'hits' => 5, 'resolved_at' => now()->subDay()]);
    siteHit(['type' => 'not_found', 'path' => '/gone']);
    siteHit(['type' => 'not_found', 'path' => '/wp-login.php'], agent: 'python-requests/2.31');

    expect(DB::table('not_found_paths')->pluck('hits', 'path')->map(fn ($n) => (int) $n)->all())->toBe(['/gone' => 6])
        ->and(DB::table('not_found_paths')->where('path', '/gone')->value('resolved_at'))->not->toBeNull();
});

test('a visitor can record only so many missing pages a day', function () {
    config(['raqam.stats.not_found_per_visitor_day' => 2]);
    foreach (['/a', '/b', '/c', '/d'] as $path) {
        siteHit(['type' => 'not_found', 'path' => $path]);
    }
    siteHit(['type' => 'not_found', 'path' => '/e'], ip: '198.51.100.9');

    expect(DB::table('not_found_paths')->orderBy('path')->pluck('path')->all())->toBe(['/a', '/b', '/e']);
});

test('a missing path longer than the column is not recorded', function () {
    siteHit(['type' => 'not_found', 'path' => '/'.str_repeat('x', 600)])->assertNoContent();

    expect(DB::table('not_found_paths')->count())->toBe(0);
});

test('an admin preview is never counted: neither its own page nor a report of it', function () {
    $user = insertUser('staff', 'editor@raqam.example');
    $post = Post::query()->find(livePost());
    $this->get(app(PostPreview::class)->url($post))->assertOk();
    siteHit(['type' => 'view', 'path' => '/preview/'.$post->id, 'post_id' => $post->id]);
    siteHit(['type' => 'not_found', 'path' => '/preview/abc']);

    expect((array) StatsKeys::redis()->keys('stats:*'))->toBe([])
        ->and(DB::table('not_found_paths')->count())->toBe(0)->and($user)->toBeInt();
});

test('reports are limited per visitor (the address the site names)', function () {
    config(['raqam.stats.requests_per_minute' => 3]);
    foreach (range(1, 3) as $i) {
        siteHit(['type' => 'view', 'path' => "/p{$i}"])->assertNoContent();
    }
    siteHit(['type' => 'view', 'path' => '/p4'])->assertStatus(429);
    siteHit(['type' => 'view', 'path' => '/p4'], ip: '198.51.100.9')->assertNoContent();
});

test('a visit that opens on a link from the site itself (a tab left open) is direct, not another site', function () {
    siteHit(['type' => 'view', 'path' => '/a', 'referrer' => 'https://raqam.example/news/1/x']);

    expect(statsHash('sources'))->toBe(['direct' => 1]);
});

test('one address cannot invent visitors by changing its browser name: a network brings at most its ceiling a day', function () {
    config(['raqam.stats.visitors_per_network_day' => 3]);
    $post = livePost();
    foreach (range(1, 6) as $i) {
        siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post], ip: '203.0.113.99', agent: PERSON_AGENT." build/{$i}");
    }
    siteHit(['type' => 'view', 'path' => '/again'], ip: '203.0.113.99', agent: PERSON_AGENT.' build/1');   // one already seen still counts
    siteHit(['type' => 'view', 'path' => '/again'], ip: '203.0.113.99', agent: PERSON_AGENT.' build/5');   // one refused stays refused
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post], ip: '198.51.100.1');       // another network

    expect(visitorsToday())->toBe(4)->and(pageViewsToday())->toBe(5)->and(statsHash('posts'))->toBe([(string) $post => 4]);
});
