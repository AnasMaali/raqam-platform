<?php

use App\Content\PostPath;
use App\Seo\FeedBoard;
use App\Seo\SitemapBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A live news item; overrides change only what a test looks at. */
function sitemapLivePost(array $overrides = []): int
{
    return insertPost(publishedPostValues($overrides));
}

/** A live success story (a section item: an article, not news). */
function sitemapStory(array $overrides = []): int
{
    $org = DB::table('organizations')->insertGetId(['name' => 'شركة '.uniqid(), 'slug' => 'org-'.uniqid()]);
    $id = insertPost(['kind_code' => 'story', 'status' => 'published', 'published_at' => now(), 'body' => '<p>x</p>', 'cover_media_id' => insertMedia(), ...$overrides]);
    DB::table('success_stories')->insert(['post_id' => $id, 'kind_code' => 'story', 'organization_id' => $org]);

    return $id;
}

function sitemapSeoMeta(bool $noindex = true, ?string $canonical = null): int
{
    return DB::table('seo_meta')->insertGetId(['robots_noindex' => $noindex, 'canonical_url' => $canonical]);
}

function sitemapPostPath(int $id): string
{
    $p = DB::table('posts')->where('id', $id)->first();

    return PostPath::for($p->kind_code, $p->id, $p->slug);
}

// ---- sitemaps (23.10) -------------------------------------------------------------------------------------------------

test('the posts sitemap lists live posts only, oldest first, in the contract shape', function () {
    $live = sitemapLivePost();
    $archived = sitemapLivePost(['status' => 'archived']);
    $draft = insertPost();
    $scheduled = sitemapLivePost(['status' => 'scheduled', 'published_at' => now()->addDay()]);
    $trashed = sitemapLivePost(['deleted_at' => now()]);
    $noindex = sitemapLivePost(['seo_meta_id' => sitemapSeoMeta()]);
    $elsewhere = sitemapLivePost(['seo_meta_id' => sitemapSeoMeta(false, 'https://other.example/a')]);
    $story = sitemapStory();

    $body = api('/sitemaps/posts')->assertOk()->json();
    validateAgainstPath($body, '/sitemaps/posts');

    expect(array_column($body['data'], 'path'))->toBe([sitemapPostPath($live), sitemapPostPath($story)])
        ->and($body['meta'])->toBe(['page' => 1, 'per_page' => SitemapBoard::POSTS_PER_PAGE, 'total' => 2, 'last_page' => 1])
        ->and($body['data'][0]['updated_at'])->toMatch('/Z$/');
    api('/sitemaps/posts?page=2')->assertNotFound();
    expect([$archived, $draft, $scheduled, $trashed, $noindex, $elsewhere])->each->toBeInt();
});

test('the sitemap index names one sitemap per page of posts, then news and videos', function () {
    sitemapLivePost();
    $body = api('/sitemaps')->assertOk()->json();
    validateAgainstPath($body, '/sitemaps');

    expect(array_column($body['data'], 'path'))->toBe(['/sitemaps/pages', '/sitemaps/posts?page=1', '/sitemaps/news', '/sitemaps/videos'])
        ->and($body['meta'])->toMatchArray(['posts_total' => 1, 'news_total' => 1]);
});

test('posts beyond one sitemap page go on the next page, split by id', function () {
    $paths = collect(range(1, 3))->map(fn () => sitemapPostPath(sitemapLivePost()));
    $board = app(SitemapBoard::class);

    expect(array_column($board->postsPage(1, 2)['entries'], 'path'))->toBe($paths->take(2)->all())
        ->and(array_column($board->postsPage(2, 2)['entries'], 'path'))->toBe([$paths[2]])
        ->and($board->postsPage(2, 2)['total'])->toBe(3);
});

test('the news sitemap holds news kinds of the last 48 hours, newest first, with their tags', function () {
    $old = sitemapLivePost(['published_at' => now()->subHours(49)]);
    $older = sitemapLivePost(['published_at' => now()->subHours(30), 'kind_code' => 'opinion']);
    $newer = sitemapLivePost(['published_at' => now()->subHour(), 'kind_code' => 'report']);
    $section = sitemapStory(['published_at' => now()->subHour()]);
    $tag = DB::table('tags')->insertGetId(['name_ar' => 'طاقة شمسية', 'slug' => 'طاقة-شمسية']);
    DB::table('post_tags')->insert(['post_id' => $newer, 'tag_id' => $tag]);
    DB::table('settings')->updateOrInsert(['key' => 'general.site_name'], ['value' => 'رَقَم', 'value_type' => 'string']);

    $body = api('/sitemaps/news')->assertOk()->json();
    validateAgainstPath($body, '/sitemaps/news');

    expect(array_column($body['data'], 'path'))->toBe([sitemapPostPath($newer), sitemapPostPath($older)])
        ->and($body['data'][0]['keywords'])->toBe(['طاقة شمسية'])
        ->and($body['meta'])->toBe(['publication' => ['name' => 'رَقَم', 'language' => 'ar'], 'hours' => 48]);
    expect($old)->toBeInt();
});

test('the pages sitemap lists what a visitor can open and leaves out the hidden, unpublished and noindex', function () {
    $active = insertCategory('طاقة-x');
    $hidden = insertCategory('مخفي-x');
    DB::table('categories')->where('id', $hidden)->update(['is_active' => false]);
    $noindexCategory = insertCategory('بدون-فهرسة');
    DB::table('categories')->where('id', $noindexCategory)->update(['seo_meta_id' => sitemapSeoMeta()]);
    DB::table('pages')->insert([['title' => 'من نحن', 'slug' => 'about', 'is_published' => true], ['title' => 'مسودة', 'slug' => 'draft-x', 'is_published' => false]]);
    DB::table('authors')->insert([['name' => 'سامر', 'slug' => 'samer', 'deleted_at' => null], ['name' => 'قديم', 'slug' => 'old-author', 'deleted_at' => now()]]);
    DB::table('sections')->where('key', 'round_table')->update(['is_visible' => false]);
    DB::table('weather_locations')->where('city_id', DB::table('cities')->where('slug', 'غزة')->value('id'))->update(['is_active' => false]);
    $post = sitemapLivePost();
    $usedTag = DB::table('tags')->insertGetId(['name_ar' => 'مستعمل', 'slug' => 'used-tag']);
    $emptyTag = DB::table('tags')->insertGetId(['name_ar' => 'فارغ', 'slug' => 'empty-tag']);
    DB::table('post_tags')->insert(['post_id' => $post, 'tag_id' => $usedTag]);

    $body = api('/sitemaps/pages')->assertOk()->json();
    validateAgainstPath($body, '/sitemaps/pages');
    $paths = array_column($body['data'], 'path');

    expect($paths)->toContain('/', '/category/طاقة-x', '/about', '/author/samer', '/podcast', '/tag/used-tag', '/currencies', '/weather', '/weather/نابلس', '/video')
        ->and(count($paths))->toBe(count(array_unique($paths)));
    // One by one: a negated toContain with several values fails only when all of them are there.
    foreach (['/category/مخفي-x', '/category/بدون-فهرسة', '/draft-x', '/author/old-author', '/round-table', '/tag/empty-tag', '/weather/غزة'] as $left) {
        expect($paths)->not->toContain($left);
    }
    expect([$active, $emptyTag])->each->toBeInt();
});

// ---- feeds (23.12) ----------------------------------------------------------------------------------------------------

test('the site feed holds the 30 latest live items, newest first, in the contract shape', function () {
    $ids = collect(range(1, 32))->map(fn ($i) => sitemapLivePost(['published_at' => now()->subMinutes(100 - $i)]));
    sitemapLivePost(['status' => 'archived']);

    $body = api('/feeds/posts')->assertOk()->json();
    validateAgainstPath($body, '/feeds/posts');

    expect($body['data']['items'])->toHaveCount(FeedBoard::ITEMS)
        ->and(array_column($body['data']['items'], 'id'))->toBe($ids->reverse()->take(30)->values()->all())
        ->and($body['data'])->toMatchArray(['title' => null, 'path' => '/']);
});

test('a category feed takes in its sub-categories; a section feed its kinds; a hidden or unknown one is a 404', function () {
    $parent = insertCategory('أسواق-feed');
    $child = insertCategory('بورصة-feed', $parent);
    $other = insertCategory('أخرى-feed');
    $inParent = sitemapLivePost(['category_id' => $parent]);
    $inChild = sitemapLivePost(['category_id' => $child]);
    sitemapLivePost(['category_id' => $other]);
    $story = sitemapStory();

    $category = api('/feeds/categories/أسواق-feed')->assertOk()->json();
    validateAgainstPath($category, '/feeds/categories/{slug}');
    expect(array_column($category['data']['items'], 'id'))->toEqualCanonicalizing([$inParent, $inChild])
        ->and($category['data']['path'])->toBe('/category/أسواق-feed');

    $section = api('/feeds/sections/success-stories')->assertOk()->json();
    expect(array_column($section['data']['items'], 'id'))->toBe([$story])->and($section['data']['path'])->toBe('/success-stories');

    DB::table('categories')->where('id', $parent)->update(['is_active' => false]);
    DB::table('sections')->where('key', 'success_stories')->update(['is_visible' => false]);
    api('/feeds/categories/أسواق-feed')->assertNotFound();
    api('/feeds/sections/success-stories')->assertNotFound();
    api('/feeds/categories/لا-يوجد')->assertNotFound();
});
