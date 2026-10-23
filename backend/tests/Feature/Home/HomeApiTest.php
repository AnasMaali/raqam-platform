<?php

use App\Navigation\HomeLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    ensurePostKinds();
    $this->layout = app(HomeLayout::class);
});

function homePost(array $overrides = []): int
{
    return insertPost(publishedPostValues(['slug' => 'h-'.bin2hex(random_bytes(4)), 'published_at' => now()->subHours(2), ...$overrides]));
}

test('the visible blocks in the admin\'s order, each with what it shows now, in the contract shape', function () {
    $c = insertCategory('اقتصاد');
    $news = homePost(['category_id' => $c, 'title' => 'خبر الاقتصاد']);
    $this->layout->save([
        homeBlock('markets'), homeBlock('category', ['category_id' => $c]), homeBlock('latest', ['title_ar' => 'جديدنا', 'item_limit' => 3]),
        homeBlock('ad', ['placement_code' => 'between_sections']), homeBlock('newsletter', ['is_active' => false]),
    ], null);

    $json = api('/home')->assertOk()->assertHeader('ETag')->json();

    expect(array_column($json['data'], 'type'))->toBe(['markets', 'category', 'latest', 'ad'])
        ->and($json['data'][0]['sources'])->toBe(['currencies' => '/api/v1/currencies/ticker', 'weather' => '/api/v1/weather/widget'])
        ->and($json['data'][1])->toMatchArray(['title' => 'اقتصاد', 'category' => ['id' => $c, 'name' => 'اقتصاد', 'slug' => 'اقتصاد']])
        ->and(array_column($json['data'][1]['posts'], 'id'))->toBe([$news])
        ->and($json['data'][2]['title'])->toBe('جديدنا')
        ->and($json['data'][3])->toMatchArray(['placement' => 'between_sections', 'title' => 'إعلان']);
    validateAgainstPath($json, '/home');
});

test('the main story is the newest featured post; the others follow, newest first', function () {
    $old = homePost(['published_at' => now()->subDays(3)]);
    $featured = homePost(['is_featured' => true, 'published_at' => now()->subDays(2)]);
    $new = homePost(['published_at' => now()->subHour()]);
    $this->layout->save([homeBlock('hero', ['item_limit' => 3])], null);

    expect(array_column(api('/home')->json('data.0.posts'), 'id'))->toBe([$featured, $new, $old]);
});

test('posts are live only, and the limit is kept', function () {
    $live = homePost();
    homePost(['status' => 'draft', 'published_at' => null]);
    homePost(['published_at' => now()->addDay()]);
    homePost(['deleted_at' => now()]);
    foreach (range(1, 4) as $_) {
        homePost(['published_at' => now()->subDays(5)]);
    }
    $this->layout->save([homeBlock('latest', ['item_limit' => 2])], null);

    $ids = array_column(api('/home')->json('data.0.posts'), 'id');
    expect($ids)->toHaveCount(2)->and($ids[0])->toBe($live);
});

test('a category block includes its sub-categories; a hidden category, section or playlist, or an empty block, is left out', function () {
    $main = insertCategory('اقتصاد');
    $sub = insertCategory('بنوك', $main);
    $hidden = insertCategory('مخفي');
    DB::table('categories')->where('id', $hidden)->update(['is_active' => false]);
    $empty = insertCategory('فارغ');
    $inSub = homePost(['category_id' => $sub]);
    homePost(['category_id' => $hidden]);
    $section = DB::table('sections')->where('key', 'podcast')->value('id');
    DB::table('sections')->where('id', $section)->update(['is_visible' => false]);
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'قائمة', 'slug' => 'list-hidden', 'is_visible' => false]);

    $this->layout->save([homeBlock('category', ['category_id' => $main]), homeBlock('category', ['category_id' => $hidden]),
        homeBlock('category', ['category_id' => $empty]), homeBlock('section', ['section_id' => $section]),
        homeBlock('playlist', ['playlist_id' => $playlist]), homeBlock('breaking')], null);

    $json = api('/home')->json('data');
    expect(array_column($json, 'type'))->toBe(['category'])->and(array_column($json[0]['posts'], 'id'))->toBe([$inSub]);
});

test('breaking, section, awareness, most read and a playlist with its videos in its order', function () {
    $breaking = homePost(['breaking_until' => now()->addHour()]);
    homePost(['breaking_until' => now()->subMinute()]);
    $story = insertPost(['kind_code' => 'story', 'title' => 'قصة', 'slug' => 'story-x', 'status' => 'published', 'body' => '<p>نص</p>',
        'published_at' => now()->subHour(), 'cover_media_id' => insertMedia()]);
    $org = DB::table('organizations')->insertGetId(['name' => 'مشغل', 'slug' => 'workshop']);
    DB::table('success_stories')->insert(['post_id' => $story, 'kind_code' => 'story', 'organization_id' => $org, 'person_name' => 'سلمى']);
    $read = homePost();
    DB::table('post_views_daily')->insert(['post_id' => $read, 'stat_date' => now('Asia/Hebron')->toDateString(), 'views' => 9]);
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'حلقات', 'slug' => 'episodes', 'is_visible' => true]);
    $second = insertYoutubeVideo('bbbbbbbbbbb');
    $first = insertYoutubeVideo('aaaaaaaaaaa');
    DB::table('playlist_videos')->insert([['playlist_id' => $playlist, 'video_id' => $second, 'sort_order' => 2], ['playlist_id' => $playlist, 'video_id' => $first, 'sort_order' => 1]]);
    $section = DB::table('sections')->where('key', 'success_stories')->value('id');

    $this->layout->save([homeBlock('breaking'), homeBlock('section', ['section_id' => $section]), homeBlock('most_read'),
        homeBlock('playlist', ['playlist_id' => $playlist])], null);
    $json = api('/home')->json('data');

    expect(array_column($json, 'type'))->toBe(['breaking', 'section', 'most_read', 'playlist'])
        ->and(array_column($json[0]['posts'], 'id'))->toBe([$breaking])
        ->and($json[1])->toMatchArray(['title' => 'قصص نجاح', 'section' => ['key' => 'success_stories', 'name' => 'قصص نجاح', 'slug' => 'success-stories']])
        ->and(array_column($json[1]['posts'], 'id'))->toBe([$story])
        ->and(array_column($json[2]['posts'], 'id'))->toBe([$read])
        ->and(array_column($json[3]['videos'], 'id'))->toBe([$first, $second]);
    validateAgainstPath(api('/home')->json(), '/home');
});

test('no layout yet: an empty list, not an error', function () {
    expect(api('/home')->assertOk()->json('data'))->toBe([]);
});

test('a hidden category is left out even when its sub-categories have posts, and a hidden section even with posts', function () {
    $hidden = insertCategory('مخفي');
    DB::table('categories')->where('id', $hidden)->update(['is_active' => false]);
    homePost(['category_id' => insertCategory('فرعي', $hidden)]);
    $story = insertPost(['kind_code' => 'story', 'title' => 'قصة', 'slug' => 'story-h', 'status' => 'published', 'body' => '<p>نص</p>',
        'published_at' => now()->subHour(), 'cover_media_id' => insertMedia()]);
    $org = DB::table('organizations')->insertGetId(['name' => 'مشغل', 'slug' => 'workshop-h']);
    DB::table('success_stories')->insert(['post_id' => $story, 'kind_code' => 'story', 'organization_id' => $org]);
    $section = DB::table('sections')->where('key', 'success_stories')->value('id');
    DB::table('sections')->where('id', $section)->update(['is_visible' => false]);

    $this->layout->save([homeBlock('category', ['category_id' => $hidden]), homeBlock('section', ['section_id' => $section])], null);

    expect(api('/home')->json('data'))->toBe([]);
});
