<?php

use App\Content\PostSaver;
use App\Jobs\GenerateMediaVariants;
use App\Media\MediaUploader;
use App\Models\Category;
use App\Models\Character;
use App\Models\Post;
use App\Models\User;
use App\Search\Searcher;
use App\Search\SearchQuery;
use App\Search\SearchResults;
use App\Search\SearchStats;
use App\Support\ArabicNormalizer;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Bus::fake([GenerateMediaVariants::class]);
    ensurePostKinds();
    config(['raqam.search.key' => '']);   // the database search; the engine is used where a test says so
    $this->user = User::factory()->staff()->create();
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    $this->category = Category::query()->whereNull('parent_id')->first();
});

afterEach(fn () => dropSearchIndexes());

function item(string $title, array $extra = [], string $kind = 'news'): Post
{
    $t = test();
    $post = app(PostSaver::class)->create($kind, $title, $t->user->id);

    return app(PostSaver::class)->save($post, ['title' => $title, 'body' => '<p>نص</p>', 'category_id' => $kind === 'news' ? $t->category->id : null,
        'cover_media_id' => $t->cover->id, 'status' => 'published', ...$extra], $t->user->id);
}

function searchApi(array $query, array $headers = [])
{
    // Headers set for one request must not stay on the next.
    return test()->flushHeaders()->withHeaders($headers)->getJson('/api/v1/search?'.http_build_query($query));
}

// ---- the answer ------------------------------------------------------------------------------------

test('results come in the contract shape, with the matched words marked in the original text', function () {
    $post = item('ارتفاعُ أسعارِ الذَّهبِ', ['excerpt' => 'الذهب <يقفز> اليوم']);

    $response = searchApi(['q' => 'الذهب'])->assertOk();

    matchesContract($response->json(), 'SearchResponse');
    expect($response->json('data.posts.0.id'))->toBe($post->id)
        ->and($response->json('data.posts.0.path'))->toBe('/news/'.$post->id.'/'.$post->slug)
        ->and($response->json('data.posts.0.highlight.title'))->toBe('ارتفاعُ أسعارِ <mark>الذَّهبِ</mark>')
        ->and($response->json('data.posts.0.highlight.excerpt'))->toBe('<mark>الذهب</mark> &lt;يقفز&gt; اليوم')
        ->and($response->json('data.engine'))->toBe('database')
        ->and($response->json('meta.total'))->toBe(1);
});

test('the answer is noindex and never cached (10.12)', function () {
    item('خبر الذهب');

    searchApi(['q' => 'ذهب'])->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('ETag');
});

test('drafts, scheduled, archived and trashed items and hidden characters\' cards never come back', function () {
    $live = item('الذهب حي');
    app(PostSaver::class)->create('news', 'الذهب مسودة', $this->user->id);
    item('الذهب مجدول', ['status' => 'scheduled', 'published_at' => now()->addDay()]);
    $archived = item('الذهب مؤرشف');
    app(PostSaver::class)->save($archived, ['title' => 'الذهب مؤرشف', 'body' => '<p>x</p>', 'category_id' => $this->category->id, 'cover_media_id' => $this->cover->id, 'status' => 'archived'], $this->user->id);
    app(PostSaver::class)->trash(item('الذهب محذوف'));
    $character = Character::create(['name_ar' => 'المستشار', 'slug' => 'advisor', 'role_ar' => 'ينصح', 'accent_color' => '#1F6F5B']);
    $pose = DB::table('character_poses')->insertGetId(['character_id' => $character->id, 'pose_type_code' => 'explaining', 'static_media_id' => $this->cover->id]);
    $card = item('الذهب بطاقة', ['awareness' => ['character_id' => $character->id, 'character_pose_id' => $pose, 'awareness_type_code' => 'advice']], 'awareness');
    $character->update(['is_active' => false]);

    expect(searchApi(['q' => 'الذهب'])->json('data.posts.*.id'))->toBe([$live->id]);
});

test('the API reads every row again: even a searcher that returned a draft would not show it', function () {
    $live = item('الذهب حي');
    $draft = app(PostSaver::class)->create('news', 'الذهب مسودة', $this->user->id);
    $hit = fn (int $id) => ['id' => $id, 'fields' => [], 'matches' => []];
    app()->instance(Searcher::class, new class([$hit($draft->id), $hit($live->id)]) extends Searcher
    {
        public function __construct(private array $hits) {}

        public function search(SearchQuery $query): SearchResults
        {
            return new SearchResults('meilisearch', $this->hits, 2);
        }
    });

    expect(searchApi(['q' => 'الذهب'])->json('data.posts.*.id'))->toBe([$live->id]);
});

test('the same holds on the engine', function () {
    useSearchEngine();
    $live = item('الذهب حي');
    item('الذهب مجدول', ['status' => 'scheduled', 'published_at' => now()->addDay()]);
    settleSearch();

    $response = searchApi(['q' => 'الذهب'])->assertOk();

    matchesContract($response->json(), 'SearchResponse');
    expect($response->json('data.engine'))->toBe('meilisearch')
        ->and($response->json('data.posts.*.id'))->toBe([$live->id])
        ->and($response->json('data.posts.0.highlight.title'))->toBe('<mark>الذهب</mark> حي');
});

test('filters and sorting are passed through, and links keep them', function () {
    $old = item('سعر الذهب أمس', ['published_at' => now()->subDays(3)]);
    $new = item('سعر الذهب اليوم');

    expect(searchApi(['q' => 'الذهب', 'kind' => 'news', 'sort' => 'latest'])->json('data.posts.*.id'))->toBe([$new->id, $old->id])
        ->and(searchApi(['q' => 'الذهب', 'category' => 'no-such-category'])->json('data.posts'))->toBe([])
        ->and(searchApi(['q' => 'الذهب', 'section' => 'no-such-section'])->json('data.posts'))->toBe([])
        ->and(searchApi(['q' => 'الذهب', 'from' => now('Asia/Hebron')->toDateString(), 'kind' => 'news'])->json('data.posts.*.id'))->toBe([$new->id]);

    foreach (range(1, 13) as $i) {
        item("الذهب رقم {$i}");
    }
    expect(searchApi(['q' => 'الذهب', 'kind' => 'news', 'track' => '1'])->json('links.next'))->toBe('?q=%D8%A7%D9%84%D8%B0%D9%87%D8%A8&kind=news&page=2');

    // Written last: an episode without its facts would stop the next save (the kind rule is checked at the end).
    insertPost(publishedPostValues(['kind_code' => 'episode', 'title' => 'حلقة عن الذهب', 'category_id' => null, 'cover_media_id' => $this->cover->id]));
    expect(searchApi(['q' => 'الذهب', 'section' => 'podcast'])->json('data.posts.*.title'))->toBe(['حلقة عن الذهب']);
});

test('bad parameters are refused in the common error shape', function (array $query) {
    $response = searchApi($query)->assertStatus(422);
    matchesContract($response->json(), 'Error');
})->with([
    'no query' => [[]],
    'one letter' => [['q' => 'ذ']],
    'too long' => [['q' => str_repeat('ذهب ', 40)]],
    'kind' => [['q' => 'ذهب', 'kind' => 'page']],
    'sort' => [['q' => 'ذهب', 'sort' => 'random']],
    'date' => [['q' => 'ذهب', 'from' => '2026-13-40']],
    'to before from' => [['q' => 'ذهب', 'from' => '2026-10-02', 'to' => '2026-10-01']],
    'page beyond 50' => [['q' => 'ذهب', 'page' => 51]],
    'slug with a slash' => [['q' => 'ذهب', 'category' => 'a/b']],
    'array' => [['q' => ['ذهب']]],
]);

test('nothing found: close words, the latest news and the most read', function () {
    $tagged = item('أسعار البنزين', ['tags' => ['المحروقات']]);
    $read = item('خبر مقروء');
    DB::table('post_views_daily')->insert(['post_id' => $read->id, 'stat_date' => now('Asia/Hebron')->toDateString(), 'views' => 40]);

    $response = searchApi(['q' => 'المحروقاب'])->assertOk();   // a typo the database search does not forgive

    matchesContract($response->json(), 'SearchResponse');
    expect($response->json('data.posts'))->toBe([])
        ->and($response->json('data.suggestions'))->toBe(['المحروقات'])
        ->and($response->json('data.latest.*.id'))->toContain($tagged->id)->toContain($read->id)
        ->and($response->json('data.most_read.*.id'))->toBe([$read->id]);
});

test('suggested words come only from searches that found something before', function () {
    item('أسعار البنزين');
    SearchStats::record('اسعار البنزين', false);    // found results
    SearchStats::record('اسعار البنزيم', true);     // found nothing: never offered

    expect(searchApi(['q' => 'اسعار البنزيت'])->json('data.suggestions'))->toBe(['اسعار البنزين']);
});

test('the highlight is found again when the engine\'s copy of the title is older than the item', function () {
    useSearchEngine();
    $post = item('الذهب يرتفع');
    settleSearch();
    // The title changes behind the index's back; the engine still holds «ذهب يرتفع».
    DB::table('posts')->where('id', $post->id)->update(['title' => 'اليوم: الذهب يرتفع بقوة']);

    expect(searchApi(['q' => 'الذهب'])->json('data.posts.0.highlight.title'))->toBe('اليوم: <mark>الذهب</mark> يرتفع بقوة');
});

test('with results there are no suggestions or fallback lists', function () {
    item('خبر الذهب');

    expect(searchApi(['q' => 'الذهب'])->json('data'))->not->toHaveKeys(['suggestions', 'latest', 'most_read']);
});

// ---- statistics (10.14) ------------------------------------------------------------------------------

test('a search is counted only with track=1, by its normalised term, with or without results', function () {
    // 22:30 UTC is already tomorrow in Palestine: the day counted is Palestine's (decision 1.5).
    $this->travelTo(Carbon::parse('2026-10-20 22:30:00', 'UTC'));
    item('أسعار الذهب');

    searchApi(['q' => 'أسعار الذهب']);                      // typing: not counted
    searchApi(['q' => 'أسعار الذهب', 'track' => '1']);
    searchApi(['q' => 'اسعار الذهب', 'track' => '1']);
    searchApi(['q' => 'لا شيء هنا أبدا', 'track' => '1']);
    searchApi(['q' => 'أسعار الذهب', 'track' => '1', 'page' => 2]);   // a next page is the same search

    $stats = DB::table('search_stats_daily')->join('search_terms', 'search_terms.id', '=', 'search_stats_daily.term_id')
        ->orderBy('normalized_term')->get(['normalized_term', 'searches', 'zero_result_searches', 'stat_date']);

    expect($stats->map(fn ($r) => [$r->normalized_term, $r->searches, $r->zero_result_searches])->all())
        ->toBe([['اسعار الذهب', 2, 0], ['لا شيء هنا ابدا', 1, 1]])
        ->and($stats->first()->stat_date)->toBe('2026-10-21');
});

test('a search that looks like an e-mail, a phone number or a long number is never recorded', function (string $q) {
    searchApi(['q' => $q, 'track' => '1'])->assertOk();

    expect(DB::table('search_terms')->count())->toBe(0)->and(DB::table('search_stats_daily')->count())->toBe(0);
})->with([
    'e-mail' => ['someone@example.com'],
    'e-mail in words' => ['رسالة إلى ahmad@mail.ps'],
    'mobile' => ['0599123456'],
    'mobile with spaces' => ['059 912 3456'],
    'international' => ['+970 59 912-3456'],
    'Arabic digits' => ['٠٥٩٩١٢٣٤٥٦'],
    'id number' => ['هوية 123456'],
    'account' => ['حساب 98765432'],
]);

test('ordinary numbers in a search are kept', function (string $q) {
    expect(SearchStats::isPersonal(ArabicNormalizer::normalize($q)))->toBeFalse();
})->with(['سعر الدولار 3.75', 'ميزانية 2026', 'عام ٢٠٢٦', 'G20', '12345']);

// ---- the limit (10.13) and the site token --------------------------------------------------------------

test('60 searches a minute per visitor, then 429 in the common shape', function () {
    foreach (range(1, 60) as $i) {
        searchApi(['q' => 'ذهب'])->assertOk();
    }

    $response = searchApi(['q' => 'ذهب'])->assertStatus(429)->assertHeader('Retry-After');
    matchesContract($response->json(), 'Error');
});

test('through the site, each visitor has their own 60; the visitor address is believed only with the token', function () {
    config(['raqam.api.site_token' => 'site-token-123']);
    $site = fn (string $ip) => ['X-Raqam-Site-Token' => 'site-token-123', 'X-Raqam-Visitor-Ip' => $ip];

    foreach (range(1, 60) as $i) {
        searchApi(['q' => 'ذهب'], $site('203.0.113.7'))->assertOk();
    }
    searchApi(['q' => 'ذهب'], $site('203.0.113.7'))->assertStatus(429);
    searchApi(['q' => 'ذهب'], $site('203.0.113.8'))->assertOk();   // another visitor behind the same server

    // Without the token the header is ignored: the caller's own address is counted, so naming a fresh one each time does not help.
    foreach (range(1, 60) as $i) {
        searchApi(['q' => 'ذهب'], ['X-Raqam-Visitor-Ip' => '198.51.100.'.$i])->assertOk();
    }
    searchApi(['q' => 'ذهب'], ['X-Raqam-Visitor-Ip' => '198.51.100.99'])->assertStatus(429);
});

test('a wrong token or a malformed visitor address does not get the site treatment', function () {
    config(['raqam.api.site_token' => 'site-token-123']);

    foreach (range(1, 60) as $i) {
        searchApi(['q' => 'ذهب'], ['X-Raqam-Site-Token' => 'wrong', 'X-Raqam-Visitor-Ip' => '203.0.113.'.$i])->assertOk();
    }
    // The site naming no valid address gets the site's own budget, not a visitor's 60.
    foreach (range(1, 61) as $i) {
        searchApi(['q' => 'ذهب'], ['X-Raqam-Site-Token' => 'site-token-123', 'X-Raqam-Visitor-Ip' => 'not-an-ip'])->assertOk();
    }
    searchApi(['q' => 'ذهب'], ['X-Raqam-Site-Token' => 'wrong', 'X-Raqam-Visitor-Ip' => '203.0.113.200'])->assertStatus(429);
});

test('the limit counters in Redis hold no raw address, neither the caller\'s nor the visitor\'s', function () {
    config(['raqam.api.site_token' => 'site-token-123', 'cache.prefix' => 'raqam_search_rl_test_'.bin2hex(random_bytes(3))]);
    app('cache')->forgetDriver('redis');
    $store = app('cache')->store('redis');
    $limiter = app(RateLimiter::class);
    (fn () => $this->cache = $store)->call($limiter);

    $connection = Redis::connection((string) config('cache.stores.redis.connection'));
    $prefix = (string) config('database.redis.options.prefix').$store->getPrefix();
    try {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23']);
        searchApi(['q' => 'ذهب'])->assertOk();
        searchApi(['q' => 'ذهب'], ['X-Raqam-Site-Token' => 'site-token-123', 'X-Raqam-Visitor-Ip' => '203.0.113.77'])->assertOk();

        $keys = array_map(fn ($k) => (string) $k, $connection->keys('*'.$store->getPrefix().'*'));
        expect($keys)->not->toBeEmpty();
        $dump = implode("\n", $keys)."\n".implode("\n", array_map(fn ($k) => (string) $connection->get(str_replace((string) config('database.redis.options.prefix'), '', $k)), $keys));
        foreach (['198.51.100.23', '203.0.113.77'] as $ip) {
            foreach ([$ip, md5($ip), sha1($ip), hash('sha256', $ip), md5('api-search'.$ip), sha1('api-search'.$ip)] as $form) {
                expect($dump)->not->toContain($form);
            }
        }
    } finally {
        foreach ($connection->keys('*'.$store->getPrefix().'*') as $key) {
            $connection->del(str_replace((string) config('database.redis.options.prefix'), '', (string) $key));
        }
    }
});
