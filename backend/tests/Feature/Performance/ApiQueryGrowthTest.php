<?php

use App\Api\Presenter;
use App\Http\Middleware\QueryReport;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * The API's lists ask the database the same number of times however many items they show (QA-5, docs/qa/performance.md):
 * each list is answered with a few items, then with more, and the two must cost the same number of queries. A query
 * per item (N+1) makes the second number larger. The QA environment's query budget counts the real data
 * (e2e/qa/query-budget.spec.ts); these tests find where the count grows, on data made for each list.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/builders.php';

/**
 * The number of database queries $request makes. The routes forget their controllers first: a test's application keeps
 * them (and what their presenter already read) from one request to the next, where the server starts each request anew.
 */
function queriesMadeBy(Closure $request): int
{
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $request();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

/** Asks $path with what $add made, then again after $add made more; both must cost the same. */
function sameQueriesWithMore(string $path, Closure $add, int $first = 2, int $more = 4): void
{
    // Each request from an empty cache of its own (QueryReport, as the QA budget counts): a cached answer would hide the queries.
    config(['raqam.qa.query_report' => true]);
    $test = test()->withHeader(QueryReport::CACHE_HEADER, 'off');
    for ($i = 0; $i < $first; $i++) {
        $add($i);
    }
    $few = queriesMadeBy(fn () => $test->getJson('/api/v1/'.$path)->assertOk());
    for ($i = $first; $i < $first + $more; $i++) {
        $add($i);
    }
    $many = queriesMadeBy(function () use ($test, $path, &$answer) {
        $answer = $test->getJson('/api/v1/'.$path)->assertOk();
    });
    expect(json_encode($answer->json()))->not->toBe('{"data":[]}', "{$path} answered with nothing: the items made are not on it");

    expect($many)->toBe($few, "{$path}: {$few} queries with {$first} items, {$many} with ".($first + $more));
}

test('the videos: the library, a playlist and the video sitemap', function (string $path) {
    sameQueriesWithMore($path, fn (int $i) => growthVideo($i));
})->with(['videos', 'playlists/قائمة', 'sitemaps/videos']);

test('the people: guests, writers and the team page', function (string $path) {
    sameQueriesWithMore($path, fn (int $i) => $path === 'guests' ? growthGuest($i) : growthAuthor($i));
})->with(['guests', 'authors', 'team']);

test('the awareness section: the characters, the section page and one character\'s cards', function () {
    sameQueriesWithMore('characters', fn (int $i) => growthCharacter($i));
});

test('the awareness section page, with more characters and cards', function () {
    ensurePostKinds();
    sameQueriesWithMore('awareness', fn (int $i) => growthCharacter($i));
});

test('one character\'s cards', function () {
    $character = growthCharacter(0);
    $pose = DB::table('character_poses')->where('character_id', $character)->value('id');
    sameQueriesWithMore('characters/شخصية-0/cards', fn () => growthCard($pose));
});

test('a round table with more guests', function () {
    $post = growthPost('roundtable', fn (int $post) => DB::table('roundtable_sessions')->insert(['post_id' => $post, 'kind_code' => 'roundtable', 'held_on' => '2026-10-01']));
    sameQueriesWithMore("posts/{$post}", fn (int $i) => DB::table('post_guests')->insert(['post_id' => $post, 'guest_id' => growthGuest($i), 'role_code' => 'guest', 'sort_order' => $i + 1]));
});

test('an article with more pictures in a gallery in its text', function () {
    $post = growthPost('report');
    $pictures = [];
    sameQueriesWithMore("posts/{$post}", function (int $i) use ($post, &$pictures) {
        $pictures[] = $media = insertMedia();
        DB::table('media_variants')->insert([
            ['media_id' => $media, 'variant' => 'md', 'format' => 'webp', 'path' => "test/{$media}_md.webp", 'width' => 720, 'height' => 540, 'size_bytes' => 100],
            ['media_id' => $media, 'variant' => 'md', 'format' => 'jpeg', 'path' => "test/{$media}_md.jpg", 'width' => 720, 'height' => 540, 'size_bytes' => 100],
        ]);
        $config = htmlspecialchars(json_encode(['media_ids' => $pictures]), ENT_QUOTES);
        DB::table('posts')->where('id', $post)->update(['body' => "<p>نص</p><div data-type=\"customBlock\" data-id=\"gallery\" data-config=\"{$config}\"></div>"]);
    });
});

test('an article with more related items', function () {
    $post = growthPost('opinion');
    sameQueriesWithMore("posts/{$post}", fn (int $i) => DB::table('post_related')->insert(['post_id' => $post, 'related_post_id' => growthPost('news'), 'sort_order' => $i + 1]));
});

test('a page of many lists (the home page) reads the kinds and the view-count switch once, and asks nothing for no categories', function () {
    $presenter = app(Presenter::class);
    $section = Post::query()->whereKey([growthPost('roundtable', fn (int $p) => DB::table('roundtable_sessions')->insert(['post_id' => $p, 'kind_code' => 'roundtable', 'held_on' => '2026-10-01']))])->get();
    $news = Post::query()->whereKey([growthPost('news')])->get();
    $presenter->summaries($news);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $presenter->summaries($news);
    $presenter->summaries($section);
    $asked = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(preg_grep('/post_kinds|feature_flags|where 0 = 1/', $asked))->toBe([]);
});

test('the services and the advertise page, with more services', function (string $path) {
    ensure('service_groups', ['name_ar' => 'إعلانات', 'sort_order' => 2]);
    $group = DB::table('service_groups')->where('name_ar', 'إعلانات')->value('id');
    DB::table('settings')->whereIn('key', ['contact.whatsapp', 'contact.phone', 'contact.email'])->delete();
    DB::table('settings')->insert([['key' => 'contact.whatsapp', 'value' => '+970599000000', 'value_type' => 'text'], ['key' => 'contact.phone', 'value' => '+970599000000', 'value_type' => 'text'], ['key' => 'contact.email', 'value' => 'ads@example.org', 'value_type' => 'text']]);
    sameQueriesWithMore($path, fn (int $i) => insertService(['group_id' => $group, 'slug' => "خدمة-{$i}", 'cover_media_id' => insertMedia(), 'currency_code' => $i % 2 ? 'USD' : 'ILS', 'sort_order' => $i + 1]));
})->with(['services', 'advertise']);

test('the ad slots, with more slots showing a house banner', function () {
    $codes = ['header_leaderboard', 'sidebar_mpu', 'in_article', 'between_sections'];
    sameQueriesWithMore('ads', fn (int $i) => DB::table('house_ads')->insert(['placement_code' => $codes[$i], 'desktop_media_id' => insertMedia(), 'mobile_media_id' => insertMedia(), 'target_path' => '/advertise', 'is_active' => true]), 1, 3);
});

test('the weather box of every city, with more cities', function () {
    ensureCurrencies();
    DB::table('weather_locations')->delete();   // the install's twelve: the box starts with none, and gains one city at a time
    $cities = DB::table('cities')->orderBy('id')->pluck('id');
    sameQueriesWithMore('weather/widget', function (int $i) use ($cities) {
        $city = $cities[$i];
        DB::table('weather_locations')->insert(['city_id' => $city, 'is_active' => true, 'sort_order' => $i + 1, 'is_default' => $i === 0]);
        $condition = DB::table('weather_conditions')->value('code');
        DB::table('weather_observations')->insert(['city_id' => $city, 'provider_code' => 'openweathermap', 'observed_at' => now()->subMinutes(10), 'temperature_c' => 20 + $i, 'condition_code' => $condition, 'is_day' => true]);
        DB::table('weather_forecasts')->insert(['city_id' => $city, 'forecast_date' => now('Asia/Hebron')->toDateString(), 'temp_min_c' => 10, 'temp_max_c' => 25, 'condition_code' => $condition, 'provider_code' => 'openweathermap']);
    });
});

test('the playlists, with more playlists', function () {
    ensurePostKinds();
    $section = DB::table('sections')->where('key', 'podcast')->value('id');
    sameQueriesWithMore('playlists', function (int $i) use ($section) {
        $playlist = DB::table('playlists')->insertGetId(['name_ar' => "قائمة {$i}", 'slug' => "قائمة-{$i}", 'section_id' => $section, 'cover_media_id' => insertMedia(), 'sort_order' => $i + 1]);
        DB::table('podcast_seasons')->insert(['season_number' => $i + 1, 'title_ar' => "الموسم {$i}", 'playlist_id' => $playlist]);
        $video = DB::table('videos')->insertGetId(['source' => 'youtube']);
        DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => sprintf('pl%09d', $i), 'source_title' => 'حلقة']);
        DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => 1]);
    });
});

test('the home page: each block more costs one query of its own (its posts), what the blocks share is read once', function () {
    ensurePostKinds();
    DB::table('home_blocks')->delete();
    $categories = [];
    for ($i = 0; $i < 3; $i++) {
        $categories[] = $category = insertCategory("تصنيف-رئيسية-{$i}");
        for ($j = 0; $j < 2; $j++) {
            insertPost(publishedPostValues(['category_id' => $category, 'title' => "خبر {$i}-{$j}", 'slug' => "خبر-رئيسية-{$i}-{$j}", 'published_at' => now()->subMinutes(10 * $i + $j)]));
        }
    }
    $section = DB::table('sections')->where('key', 'round_table')->value('id');
    growthPost('roundtable', fn (int $p) => DB::table('roundtable_sessions')->insert(['post_id' => $p, 'kind_code' => 'roundtable', 'held_on' => '2026-10-01']));
    // The first two already hold a category and a section: what those kinds of block share is then read in both counts.
    $blocks = [['block_type' => 'category', 'category_id' => $categories[0]], ['block_type' => 'section', 'section_id' => $section], ['block_type' => 'latest'],
        ['block_type' => 'category', 'category_id' => $categories[1]], ['block_type' => 'section', 'section_id' => $section], ['block_type' => 'category', 'category_id' => $categories[2]]];
    $add = fn (int $i) => DB::table('home_blocks')->insert($blocks[$i] + ['sort_order' => $i + 1, 'is_active' => true]);

    config(['raqam.qa.query_report' => true]);
    $this->withHeader(QueryReport::CACHE_HEADER, 'off');
    $add(0);
    $add(1);
    $few = queriesMadeBy(fn () => $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(2, 'data'));
    foreach ([2, 3, 4, 5] as $i) {
        $add($i);
    }
    $many = queriesMadeBy(fn () => $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(6, 'data'));

    expect($many - $few)->toBeLessThanOrEqual(4, "home: {$few} queries with 2 blocks, {$many} with 6");
});
