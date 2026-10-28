<?php

use App\Content\PostPublisher;
use App\Content\PostSaver;
use App\Content\StaticPages;
use App\Jobs\GenerateMediaVariants;
use App\Jobs\PublishScheduledPost;
use App\Media\MediaUploader;
use App\Media\VariantGenerator;
use App\Models\Author;
use App\Models\Category;
use App\Models\Era;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    Bus::fake([GenerateMediaVariants::class, PublishScheduledPost::class]);
    ensurePostKinds();
    $this->user = User::factory()->staff()->create();
    $this->actingAs($this->user);
    $this->saver = app(PostSaver::class);
    $this->cover = app(MediaUploader::class)->store(uploadFile(imageBytes(300, 200)), 'c.jpg', ['alt_text' => 'غلاف'], null)->asset;
    app(VariantGenerator::class)->generate($this->cover);
    $this->energy = Category::where('slug', 'طاقة')->first();
    auth()->logout();
});

function publish(string $kind = 'news', array $extra = []): Post
{
    $t = test();
    $post = app(PostSaver::class)->create($kind, $extra['title'] ?? 'عنوان '.$kind.' '.random_int(1, 999999), $t->user->id);

    return app(PostSaver::class)->save($post, array_merge(['title' => $post->title, 'body' => '<p>نص المادة</p>', 'category_id' => $t->energy->id, 'cover_media_id' => $t->cover->id, 'status' => 'published'], $extra), $t->user->id);
}

// ---- the contract itself -------------------------------------------------------------------------

test('every route of the API is in the contract and every path of the contract is a route', function () {
    $spec = Yaml::parseFile(base_path('../docs/api/openapi.yaml'));
    $documented = collect($spec['paths'])->keys()->map(fn ($p) => '/api/v1'.preg_replace('/\{(\w+)\}/', '{$1}', $p))->sort()->values()->all();
    $actual = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1'))->map(fn ($r) => '/'.$r->uri())->unique()->sort()->values()->all();

    expect($actual)->toBe($documented);
    // A signed-in customer's own data (decision 17, switched off): the only routes that change something of a person.
    $account = ['/account/register' => ['post'], '/account/register/resend' => ['post'], '/account/register/confirm' => ['post'], '/account/login' => ['post'],
        '/account/password/forgot' => ['post'], '/account/password/reset' => ['post'], '/account/logout' => ['post'], '/account/profile' => ['get', 'patch'],
        '/account/password' => ['post'], '/account' => ['delete'], '/account/saved' => ['get'], '/account/saved/{post}' => ['put', 'delete'],
        '/account/follows' => ['get'], '/account/follows/{kind}/{id}' => ['put', 'delete'], '/account/bookings' => ['get']];
    $allowed = function (string $path) use ($account, &$counters) {
        return $account[$path] ?? (in_array($path, $counters, true) ? ['post'] : ['get']);
    };
    // Read only, except the counters of what visitors do (they write a count, never content) and the public forms
    // of the site's server (they keep a message for the panel; nothing they send is ever published).
    $counters = ['/ads/impressions', '/services/{slug}/contact', '/contact', '/newsletter/subscribe', '/newsletter/resend', '/newsletter/confirm',
        '/newsletter/unsubscribe', '/newsletter/brevo-webhook', '/chat/messages', '/stats/hits', '/bookings', '/bookings/track', '/bookings/pay',
        '/payments/return', '/payments/webhooks/{gateway}'];
    foreach ($spec['paths'] as $template => $path) {
        expect(array_keys($path))->toBe($allowed($template), $template);
    }
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1')) {
            $path = substr('/'.$route->uri(), 7);
            $methods = array_values(array_diff(array_map('strtolower', $route->methods()), ['head']));
            expect($methods)->toHaveCount(1)->and($allowed($path))->toContain($methods[0]);
        }
    }
});

test('nothing but GET exists', function () {
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->{$method.'Json'}('/api/v1/posts')->assertStatus(405);
    }
});

// ---- posts ---------------------------------------------------------------------------------------

test('the list shows published posts only, newest first, 12 to a page, in the contract shape', function () {
    $live = collect(range(1, 14))->map(fn ($i) => publish('news', ['title' => "خبر {$i}", 'published_at' => now()->subMinutes(30 - $i)]));
    $draft = app(PostSaver::class)->create('news', 'مسودة', $this->user->id);
    $scheduled = publish('news', ['title' => 'مجدول', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
    $archived = publish('news', ['title' => 'مؤرشف']);
    $this->saver->save($archived, ['title' => 'مؤرشف', 'body' => '<p>x</p>', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'status' => 'archived'], $this->user->id);
    $trashed = publish('news', ['title' => 'محذوف']);
    $this->saver->trash($trashed);

    $first = api('/posts')->assertOk()->json();
    expect($first['data'])->toHaveCount(12)->and($first['meta'])->toMatchArray(['page' => 1, 'per_page' => 12, 'total' => 14, 'last_page' => 2])
        ->and($first['links'])->toMatchArray(['next' => '?page=2', 'prev' => null]);
    expect(collect($first['data'])->pluck('title')->first())->toBe('خبر 14');
    foreach ($first['data'] as $item) {
        matchesContract($item, 'PostSummary');
    }

    $second = api('/posts?page=2')->assertOk()->json();
    expect($second['data'])->toHaveCount(2)->and($second['links'])->toMatchArray(['next' => null, 'prev' => '?page=1']);

    $titles = collect($first['data'])->merge($second['data'])->pluck('title');
    expect($titles)->not->toContain('مسودة')->not->toContain('مجدول')->not->toContain('مؤرشف')->not->toContain('محذوف');
});

test('a scheduled item appears at its time and not before', function () {
    $post = publish('news', ['status' => 'scheduled', 'published_at' => now()->addHour()]);
    expect(api('/posts')->json('meta.total'))->toBe(0);

    $this->travel(61)->minutes();
    app(PostPublisher::class)->publishIfDue($post->id);

    expect(api('/posts')->json('meta.total'))->toBe(1);
});

test('filters narrow the list and are carried into the next page link', function () {
    $world = Category::where('slug', 'تكنولوجيا')->first();
    $sub = Category::create(['name_ar' => 'الطاقة الشمسية', 'slug' => 'solar', 'parent_id' => $this->energy->id]);
    $a = publish('news', ['title' => 'أ', 'is_featured' => true, 'breaking_until' => now()->addHour()]);
    $b = publish('report', ['title' => 'ب', 'category_id' => $world->id, 'tags' => ['نفط']]);
    $c = publish('news', ['title' => 'ج', 'category_id' => $sub->id]);
    $author = Author::create(['name' => 'كاتب', 'slug' => 'writer']);
    $this->saver->save($c, ['title' => 'ج', 'body' => '<p>x</p>', 'category_id' => $sub->id, 'cover_media_id' => $this->cover->id, 'status' => 'published', 'authors' => [$author->id]], $this->user->id);

    $titles = fn (string $q) => collect(api('/posts?'.$q)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

    expect($titles('kind=report'))->toBe(['ب'])
        ->and($titles('category=طاقة'))->toBe(['أ', 'ج'])        // a main category includes its sub-categories
        ->and($titles('category=solar'))->toBe(['ج'])
        ->and($titles('tag='.DB::table('tags')->value('slug')))->toBe(['ب'])
        ->and($titles('author=writer'))->toBe(['ج'])
        ->and($titles('featured=1'))->toBe(['أ'])
        ->and($titles('breaking=1'))->toBe(['أ'])
        ->and($titles('category=nothing'))->toBe([]);

    collect(range(1, 13))->each(fn ($i) => publish('report', ['title' => "تقرير {$i}"]));
    expect(api('/posts?kind=report')->json('links.next'))->toBe('?kind=report&page=2');
});

test('section and guest filters', function () {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 1]);
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'guest-1']);
    $episode = publish('episode', ['title' => 'حلقة', 'category_id' => null, 'episode' => ['season_id' => $season, 'episode_number' => 1], 'guests' => [['guest_id' => $guest->id, 'role_code' => 'host']]]);
    publish('news', ['title' => 'خبر']);

    expect(collect(api('/posts?section=podcast')->json('data'))->pluck('id')->all())->toBe([$episode->id])
        ->and(collect(api('/posts?guest=guest-1')->json('data'))->pluck('id')->all())->toBe([$episode->id]);

    DB::table('sections')->where('slug', 'podcast')->update(['is_visible' => false]);
    expect(api('/posts?section=podcast')->json('data'))->toBe([]);
});

test('wrong parameters are answered with 422 in the common error shape', function (string $query) {
    $r = api('/posts?'.$query)->assertStatus(422);
    matchesContract($r->json(), 'Error');
    expect($r->json('error.code'))->toBe('invalid');
})->with(['page=0', 'page=abc', 'page=99999', 'kind=banana', 'featured=2', 'category=a/b', 'tag=a%20b', 'author[]=x']);

test('a post has everything the contract promises', function () {
    $post = publish('news', ['title' => 'خبر كامل', 'subtitle' => 'فرعي', 'excerpt' => 'ملخص', 'tags' => ['ذهب'], 'gallery' => [$this->cover->id], 'correction_note' => 'صُحّح الرقم']);

    $data = api("/posts/{$post->id}")->assertOk()->json('data');

    matchesContract($data, 'PostDetail');
    expect($data)->toMatchArray(['id' => $post->id, 'title' => 'خبر كامل', 'path' => "/news/{$post->id}/".$post->fresh()->slug, 'is_breaking' => false, 'sponsor' => null])
        ->and($data['tags'][0]['name'])->toBe('ذهب')->and($data['gallery'])->toHaveCount(1)->and($data['correction']['note'])->toBe('صُحّح الرقم')
        ->and($data['cover']['variants'])->toHaveKey('thumb')->and($data['cover']['variants']['og'])->toHaveKey('jpeg')
        ->and($data['details'])->toBeNull()->and($data['seo']['title'])->toBe('خبر كامل');
});

test('every kind of post is in the contract with its own facts', function (string $kind) {
    $season = DB::table('podcast_seasons')->insertGetId(['season_number' => 2]);
    $org = Organization::create(['name' => 'شركة', 'slug' => 'co-'.$kind, 'website' => 'https://co.example']);
    $character = DB::table('characters')->insertGetId(['name_ar' => 'رَقَم', 'slug' => 'raqam', 'role_ar' => 'الراوي', 'accent_color' => '#C8962B']);
    $pose = DB::table('character_poses')->insertGetId(['character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => $this->cover->id]);
    $facts = match ($kind) {
        'episode' => ['season_id' => $season, 'episode_number' => 3],
        'story' => ['organization_id' => $org->id, 'person_name' => 'أبو خالد', 'figures' => [['value' => '5', 'label' => 'فروع']]],
        'product' => ['product_name' => 'صابون', 'organization_id' => $org->id, 'contact_phone' => '+970599123456'],
        'history' => ['era_id' => Era::create(['name_ar' => 'عثماني', 'sort_order' => 1])->id, 'year_from' => 1850, 'year_to' => 1900],
        'initiative' => ['initiative_name' => 'مبادرة', 'organizer_organization_id' => $org->id, 'status' => 'ongoing'],
        'roundtable' => ['held_on' => '2026-09-01'],
        'awareness' => ['character_pose_id' => $pose, 'awareness_type_code' => 'warning'],
        'infographic' => ['download_media_id' => $this->cover->id],
        default => [],
    };
    $post = publish($kind, [$kind => $facts]);

    $data = api("/posts/{$post->id}")->assertOk()->json('data');

    matchesContract($data, 'PostDetail');
    if ($facts !== []) {
        expect($data['details']['kind'])->toBe($kind);
    }
})->with(['news', 'report', 'opinion', 'infographic', 'episode', 'story', 'product', 'history', 'initiative', 'roundtable', 'awareness']);

test('the text is cleaned HTML with its blocks already built', function () {
    $other = publish('news', ['title' => 'مادة أخرى']);
    $body = '<p>مقدمة <a href="https://example.com">رابط</a></p><div data-type="customBlock" data-id="image" data-config=\'{"media_id":'.$this->cover->id.',"caption":"لقطة"}\'></div><div data-type="customBlock" data-id="read-also" data-config=\'{"post_ids":['.$other->id.']}\'></div>';
    $post = publish('news', ['body' => $body]);

    $html = api("/posts/{$post->id}")->json('data.body_html');

    expect($html)->toContain('<figure class="rq-image">')->toContain('لقطة')->toContain('مادة أخرى')->not->toContain('customBlock')->not->toContain('<script');
    expect(api("/posts/{$post->id}")->json('data.related.0.id'))->toBe($other->id);
});

test('sponsored content is marked and its links say rel=sponsored', function () {
    $org = Organization::create(['name' => 'راعٍ', 'slug' => 'sp']);
    $post = publish('news', ['sponsor_organization_id' => $org->id, 'body' => '<p><a href="https://adv.example">إعلان</a></p>']);

    $data = api("/posts/{$post->id}")->json('data');

    expect($data['sponsor']['name'])->toBe('راعٍ')->and($data['body_html'])->toContain('rel="sponsored noopener noreferrer"');
});

test('related posts that are not published never show', function () {
    $draft = app(PostSaver::class)->create('news', 'سري', $this->user->id);
    $post = publish('news', ['body' => '<p>x</p><div data-type="customBlock" data-id="read-also" data-config=\'{"post_ids":['.$draft->id.']}\'></div>']);

    expect(api("/posts/{$post->id}")->json('data.related'))->toBe([])->and(api("/posts/{$post->id}")->json('data.body_html'))->not->toContain('سري');
});

test('a post that is not public is not found, whatever its state', function () {
    $draft = app(PostSaver::class)->create('news', 'مسودة', $this->user->id);
    $scheduled = publish('news', ['status' => 'scheduled', 'published_at' => now()->addDay()]);
    foreach ([$draft->id, $scheduled->id, 987654] as $id) {
        matchesContract(api("/posts/{$id}")->assertNotFound()->json(), 'Error');
    }
});

test('an archived post keeps working at its address but leaves every list', function () {
    $post = publish('news', ['title' => 'قديم']);
    $this->saver->save($post, ['title' => 'قديم', 'body' => '<p>x</p>', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'status' => 'archived'], $this->user->id);
    $current = publish('news', ['title' => 'حالي', 'body' => '<p>x</p><div data-type="customBlock" data-id="read-also" data-config=\'{"post_ids":['.$post->id.']}\'></div>']);

    matchesContract(api("/posts/{$post->id}")->assertOk()->json('data'), 'PostDetail');
    api("/posts/{$post->id}/".rawurlencode('غير-الرابط'))->assertStatus(301);
    expect(collect(api('/posts')->json('data'))->pluck('title')->all())->toBe(['حالي'])
        ->and(api("/posts/{$current->id}")->json('data.related'))->toBe([])
        ->and(api('/posts?category=طاقة')->json('meta.total'))->toBe(1);
});

test('every kind of the news family lives under /news, and the sections under their own address', function () {
    foreach (['news', 'report', 'opinion', 'infographic'] as $kind) {
        $facts = $kind === 'infographic' ? ['infographic' => ['download_media_id' => $this->cover->id]] : [];
        $post = publish($kind, $facts);
        expect(api("/posts/{$post->id}")->json('data.path'))->toStartWith("/news/{$post->id}/");
    }
});

test('a deleted post answers 410, in the trash and after it was removed for good', function () {
    $post = publish('news');
    $this->saver->trash($post);
    matchesContract(api("/posts/{$post->id}")->assertStatus(410)->json(), 'Error');

    $this->saver->purge(Post::withTrashed()->find($post->id));
    api("/posts/{$post->id}")->assertStatus(410);
    api('/posts/'.($post->id + 100))->assertNotFound();
});

test('an old slug answers 301 to the current address, the current slug answers 200', function () {
    $post = publish('news', ['title' => 'عنوان قديم']);
    $old = $post->fresh()->slug;
    $this->saver->save($post->fresh(), ['title' => 'عنوان قديم', 'body' => '<p>x</p>', 'category_id' => $this->energy->id, 'cover_media_id' => $this->cover->id, 'status' => 'published', 'slug' => 'عنوان-جديد'], $this->user->id);

    $r = api("/posts/{$post->id}/".rawurlencode($old))->assertStatus(301);
    expect($r->json('path'))->toBe("/news/{$post->id}/عنوان-جديد")->and($r->headers->get('Location'))->toContain("/api/v1/posts/{$post->id}/");
    api("/posts/{$post->id}/".rawurlencode('عنوان-جديد'))->assertOk();
    api("/posts/{$post->id}/".rawurlencode('أي-شيء'))->assertStatus(301);
});

test('the id in the path must be a number', function () {
    api('/posts/abc')->assertNotFound();
    api('/posts/1%27%20OR%20%271')->assertNotFound();
});

// ---- the rest ------------------------------------------------------------------------------------

test('categories come as a tree of the visible ones, in order', function () {
    Category::create(['name_ar' => 'فرعي', 'slug' => 'sub', 'parent_id' => $this->energy->id, 'sort_order' => 1]);
    Category::create(['name_ar' => 'مخفي', 'slug' => 'hidden', 'parent_id' => $this->energy->id, 'is_active' => false]);
    Category::where('slug', 'فلسطين')->update(['is_active' => false]);

    $data = api('/categories')->assertOk()->json('data');

    foreach ($data as $node) {
        matchesContract($node, 'CategoryNode');
    }
    $slugs = collect($data)->pluck('slug')->all();
    expect($slugs)->toContain('طاقة')->not->toContain('فلسطين')
        ->and(collect($data)->firstWhere('slug', 'طاقة')['children'])->toHaveCount(1);
    matchesContract(api('/categories/sub')->assertOk()->json('data'), 'CategoryNode');
    expect(api('/categories/sub')->json('data.parent.slug'))->toBe('طاقة');
    api('/categories/hidden')->assertNotFound();
    api('/categories/فلسطين')->assertNotFound();
});

test('tags list only those with published posts, the most used first', function () {
    publish('news', ['tags' => ['ذهب', 'نفط']]);
    publish('news', ['tags' => ['ذهب']]);
    $draft = app(PostSaver::class)->create('news', 'م', $this->user->id);
    $this->saver->save($draft, ['title' => 'م', 'tags' => ['سري']], $this->user->id);

    $r = api('/tags')->assertOk()->json();

    expect(collect($r['data'])->pluck('name')->all())->toBe(['ذهب', 'نفط'])->and($r['data'][0]['posts_count'])->toBe(2);
    matchesContract($r['data'][0], 'Tag');
    api('/tags/'.DB::table('tags')->where('name_ar', 'سري')->value('slug'))->assertNotFound();
    matchesContract(api('/tags/'.DB::table('tags')->where('name_ar', 'ذهب')->value('slug'))->assertOk()->json('data'), 'Tag');
});

test('sections are the visible ones, with their picture', function () {
    DB::table('sections')->where('key', 'awareness')->update(['is_visible' => false, 'cover_media_id' => null]);
    DB::table('sections')->where('key', 'podcast')->update(['cover_media_id' => $this->cover->id]);

    $data = api('/sections')->assertOk()->json('data');

    expect($data)->toHaveCount(6)->and(collect($data)->pluck('key'))->not->toContain('awareness');
    foreach ($data as $section) {
        matchesContract($section, 'Section');
    }
    expect(collect($data)->firstWhere('key', 'podcast')['cover']['id'])->toBe($this->cover->id);
    api('/sections/awareness')->assertNotFound();
    api('/sections/podcast')->assertOk();
});

test('writers and guests: active and live ones only, with links, photo and organisation', function () {
    $author = Author::create(['name' => 'ألف', 'slug' => 'alef', 'photo_media_id' => $this->cover->id, 'team_sort_order' => 1]);
    DB::table('author_links')->insert(['author_id' => $author->id, 'platform_code' => 'x', 'url' => 'https://x.com/alef']);
    Author::create(['name' => 'باء', 'slug' => 'ba', 'is_active' => false]);
    Author::create(['name' => 'جيم', 'slug' => 'jim'])->delete();
    $org = Organization::create(['name' => 'شركة', 'slug' => 'co']);
    $guest = Guest::create(['name' => 'ضيف', 'slug' => 'dayf', 'organization_id' => $org->id]);
    Guest::create(['name' => 'محذوف', 'slug' => 'gone'])->delete();

    $authors = api('/authors')->assertOk()->json();
    expect(collect($authors['data'])->pluck('slug')->all())->toBe(['alef']);
    matchesContract($authors['data'][0], 'Author');
    expect($authors['data'][0]['links'])->toBe(['x' => 'https://x.com/alef']);
    api('/authors/ba')->assertNotFound();
    api('/authors/jim')->assertNotFound();

    $guests = api('/guests')->assertOk()->json();
    expect(collect($guests['data'])->pluck('slug')->all())->toBe(['dayf']);
    matchesContract($guests['data'][0], 'Guest');
    api('/guests/gone')->assertNotFound();
    expect(api('/guests/dayf')->json('data.organization.name'))->toBe('شركة');
});

test('private files never appear: a writer whose photo is private shows no photo', function () {
    $private = app(MediaUploader::class)->store(uploadFile(imageBytes(310, 200)), 'p.jpg', ['visibility' => 'private'], null)->asset;
    Author::create(['name' => 'ألف', 'slug' => 'alef', 'photo_media_id' => $private->id]);

    expect(api('/authors/alef')->json('data.photo'))->toBeNull();
});

test('only published pages are served', function () {
    (new StaticPages)->install();
    Page::where('system_key', 'about')->update(['is_published' => true, 'body' => '<p>من نحن</p><script>alert(1)</script>']);

    $list = api('/pages')->assertOk()->json('data');
    expect($list)->toHaveCount(1);
    matchesContract($list[0], 'PageSummary');

    $page = api('/pages/about')->assertOk()->json('data');
    matchesContract($page, 'Page');
    api('/pages/privacy')->assertNotFound();
});

test('redirects: moved, gone, unknown, and each answer counts as a visit', function () {
    DB::table('redirects')->insert([
        ['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301],
        ['from_path' => '/temp', 'to_path' => 'https://other.example/x', 'status_code' => 302],
        ['from_path' => '/removed', 'to_path' => null, 'status_code' => 410],
    ]);

    $r = api('/redirects/lookup?path='.rawurlencode('/old'))->assertStatus(301);
    expect($r->json('to'))->toBe('/new')->and($r->headers->get('Location'))->toBe('/new');
    api('/redirects/lookup?path=/temp')->assertStatus(302);
    api('/redirects/lookup?path=/removed')->assertStatus(410);
    api('/redirects/lookup?path=/nothing')->assertNotFound();
    api('/redirects/lookup?path=nothing')->assertStatus(422);
    api('/redirects/lookup')->assertStatus(422);

    expect((int) DB::table('redirects')->where('from_path', '/old')->value('hits'))->toBe(1)->and(DB::table('redirects')->where('from_path', '/old')->value('last_hit_at'))->not->toBeNull();
});

// ---- caching and limits --------------------------------------------------------------------------

test('every answer has an ETag, and the same request with it gets 304', function () {
    publish('news');

    $first = api('/posts')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($first->headers->get('Cache-Control'))->toContain('public')->toContain('s-maxage');

    api('/posts', ['If-None-Match' => $etag])->assertStatus(304);
    api('/posts', ['If-None-Match' => '"other"'])->assertOk();

    publish('news');
    api('/posts', ['If-None-Match' => $etag])->assertOk();   // content changed: the old validator no longer matches
});

test('errors are never cached', function () {
    expect(api('/posts/987654')->assertNotFound()->headers->get('Cache-Control'))->toContain('no-store');
});

test('a client that asks too often is told to wait', function () {
    for ($i = 0; $i < 120; $i++) {
        api('/sections')->assertSuccessful();
    }

    $r = api('/sections')->assertStatus(429);
    matchesContract($r->json(), 'Error');
    expect($r->headers->get('Retry-After'))->not->toBeNull();
});

test('the website, holding the secret token, has a much higher limit; a wrong or empty token has the ordinary one', function () {
    config(['raqam.api.site_token' => 'site-secret-0123456789', 'raqam.api.limit' => 3, 'raqam.api.site_limit' => 10]);

    for ($i = 0; $i < 10; $i++) {
        api('/sections', ['X-Raqam-Site-Token' => 'site-secret-0123456789'])->assertOk();
    }
    api('/sections', ['X-Raqam-Site-Token' => 'site-secret-0123456789'])->assertStatus(429);

    foreach (range(1, 3) as $_) {
        api('/tags', ['X-Raqam-Site-Token' => 'wrong'])->assertOk();
    }
    api('/tags', ['X-Raqam-Site-Token' => 'wrong'])->assertStatus(429);
});

test('with no token configured an empty header does not unlock the higher limit', function () {
    config(['raqam.api.site_token' => '', 'raqam.api.limit' => 2, 'raqam.api.site_limit' => 50]);

    api('/pages', ['X-Raqam-Site-Token' => ''])->assertOk();
    api('/pages', ['X-Raqam-Site-Token' => ''])->assertOk();
    api('/pages', ['X-Raqam-Site-Token' => ''])->assertStatus(429);
});

test('the API answers JSON even when asked for HTML, and an unknown path is a JSON 404', function () {
    $this->get('/api/v1/sections', ['Accept' => 'text/html'])->assertOk()->assertHeader('Content-Type', 'application/json');
    $this->get('/api/v1/nothing-here', ['Accept' => 'text/html'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

test('no query can read a draft through a filter or an id trick', function () {
    $draft = app(PostSaver::class)->create('news', 'مسودة سرية', $this->user->id);

    foreach (['/posts?kind=news', '/posts?category=طاقة', "/posts/{$draft->id}", "/posts/{$draft->id}/x"] as $path) {
        expect(json_encode(api($path)->json(), JSON_UNESCAPED_UNICODE))->not->toContain('مسودة سرية');
    }
});

test('every item says the schema.org type of its JSON-LD (23.9)', function () {
    $news = publish('news');
    $opinion = publish('opinion');

    expect(api("/posts/{$news->id}")->json('data.kind.schema_type'))->toBe('NewsArticle')
        ->and(api("/posts/{$opinion->id}")->json('data.kind.schema_type'))->toBe('OpinionNewsArticle')
        ->and(collect(api('/posts')->json('data'))->pluck('kind.schema_type')->unique()->sort()->values()->all())->toBe(['NewsArticle', 'OpinionNewsArticle']);
});

test('the team page lists the writers the admin put on it, in the admin\'s order, active only (28)', function () {
    DB::table('authors')->insert([
        ['name' => 'رئيس التحرير', 'slug' => 'chief', 'team_sort_order' => 1, 'is_active' => true, 'deleted_at' => null],
        ['name' => 'محررة', 'slug' => 'editor', 'team_sort_order' => 0, 'is_active' => true, 'deleted_at' => null],
        ['name' => 'كاتب ضيف', 'slug' => 'guest-writer', 'team_sort_order' => null, 'is_active' => true, 'deleted_at' => null],
        ['name' => 'سابق', 'slug' => 'former', 'team_sort_order' => 2, 'is_active' => false, 'deleted_at' => null],
        ['name' => 'محذوف', 'slug' => 'gone', 'team_sort_order' => 3, 'is_active' => true, 'deleted_at' => now()],
    ]);

    // (A link each: the test's JSON decoding turns an empty links object into a list.)
    foreach (['chief', 'editor'] as $slug) {
        DB::table('author_links')->insert(['author_id' => DB::table('authors')->where('slug', $slug)->value('id'), 'platform_code' => 'x', 'url' => "https://x.com/{$slug}"]);
    }
    $body = api('/team')->assertOk()->json();
    validateAgainstPath($body, '/team');

    expect(array_column($body['data'], 'slug'))->toBe(['editor', 'chief']);
});

test('initiatives can be listed by state, and each carries its state in the list (5.5)', function () {
    $org = Organization::create(['name' => 'غرفة تجارة نابلس', 'slug' => 'chamber']);
    $ongoing = publish('initiative', ['initiative' => ['initiative_name' => 'مبادرة جارية', 'organizer_organization_id' => $org->id, 'status' => 'ongoing']]);
    $ended = publish('initiative', ['initiative' => ['initiative_name' => 'مبادرة منتهية', 'organizer_organization_id' => $org->id, 'status' => 'ended']]);
    publish('news');

    $list = api('/posts?section=initiatives&initiative_status=ended')->assertOk()->json();
    validateAgainstPath($list, '/posts');

    expect(array_column($list['data'], 'id'))->toBe([$ended->id])
        ->and($list['data'][0]['facts'])->toBe(['initiative_name' => 'مبادرة منتهية', 'status' => 'ended'])
        ->and(array_column(api('/posts?initiative_status=ongoing')->json('data'), 'id'))->toBe([$ongoing->id])
        ->and(collect(api('/posts?kind=news')->json('data'))->pluck('facts')->unique()->all())->toBe([null]);
    api('/posts?initiative_status=paused')->assertStatus(422);
});

test('the history section can be read as a timeline: era, then year, oldest first (5.4)', function () {
    $ottoman = Era::create(['name_ar' => 'العثماني', 'sort_order' => 1]);
    $mandate = Era::create(['name_ar' => 'الانتداب', 'sort_order' => 2]);
    $late = publish('history', ['history' => ['era_id' => $mandate->id, 'year_from' => 1930], 'published_at' => now()->subDay()]);
    // Published last, but the oldest year: only the year puts it first.
    $early = publish('history', ['history' => ['era_id' => $ottoman->id, 'year_from' => 1850], 'published_at' => now()->subHour()]);
    $middle = publish('history', ['history' => ['era_id' => $ottoman->id, 'year_from' => 1900], 'published_at' => now()->subDays(2)]);

    $timeline = api('/posts?section=history&order=timeline')->assertOk()->json();
    validateAgainstPath($timeline, '/posts');

    expect(array_column($timeline['data'], 'id'))->toBe([$early->id, $middle->id, $late->id])
        ->and($timeline['data'][0]['facts'])->toMatchArray(['era' => ['id' => $ottoman->id, 'name' => 'العثماني'], 'year_from' => 1850])
        ->and(array_column(api('/posts?section=history')->json('data'), 'id'))->toBe([$early->id, $late->id, $middle->id])   // without it: newest published first
        ->and($timeline['links']['next'])->toBeNull();
    expect(api('/posts?section=history&order=timeline&page=1')->json('links.prev'))->toBeNull();
});

// ---- section lists: facts, filters and /news (5.9) -------------------------------------------------------------------

/** Two sectors with a company each, two podcast seasons, two characters with a pose each (each pose its own picture). */
function sectionFixtures(): array
{
    // Different sizes: the same bytes twice would be the same picture.
    $picture = fn (string $name, int $size) => app(MediaUploader::class)->store(uploadFile(imageBytes($size, $size)), $name, ['alt_text' => 'شخصية'], null)->asset->id;
    $food = DB::table('sectors')->insertGetId(['name_ar' => 'الصناعات الغذائية', 'slug' => 'food', 'sort_order' => 1]);
    $soap = DB::table('sectors')->insertGetId(['name_ar' => 'الصابون', 'slug' => 'soap', 'sort_order' => 2]);
    $character = fn (string $name, string $slug, int $order) => DB::table('characters')->insertGetId(['name_ar' => $name, 'slug' => $slug, 'role_ar' => 'دور', 'accent_color' => '#124FFF', 'sort_order' => $order]);
    $pose = fn (int $character, int $media) => DB::table('character_poses')->insertGetId(['character_id' => $character, 'pose_type_code' => 'explaining', 'static_media_id' => $media]);

    return [
        'dairy' => Organization::create(['name' => 'ألبان الوادي', 'slug' => 'dairy', 'sector_id' => $food]),
        'soapCo' => Organization::create(['name' => 'صابون الجبل', 'slug' => 'soap-co', 'sector_id' => $soap]),
        'season1' => DB::table('podcast_seasons')->insertGetId(['season_number' => 1, 'title_ar' => 'البدايات']),
        'season2' => DB::table('podcast_seasons')->insertGetId(['season_number' => 2]),
        'raqamPose' => $pose($character('رَقَم', 'raqam', 1), $picture('raqam.jpg', 200)),
        'watchPose' => $pose($character('انتبه!', 'watch-out', 2), $watch = $picture('watch.jpg', 210)),
        'watchPicture' => $watch,
    ];
}

test('section items carry their own facts in lists, so a card needs no second request (5.1-5.7)', function () {
    $f = sectionFixtures();
    $episode = publish('episode', ['episode' => ['season_id' => $f['season2'], 'episode_number' => 4]]);
    $story = publish('story', ['story' => ['organization_id' => $f['dairy']->id, 'person_name' => 'أم أحمد', 'figures' => [['value' => '120', 'label' => 'موظفاً'], ['value' => '3', 'label' => 'فروع']]]]);
    $product = publish('product', ['product' => ['product_name' => 'صابون زيت الزيتون', 'organization_id' => $f['soapCo']->id]]);
    $table = publish('roundtable', ['roundtable' => ['held_on' => '2026-09-01']]);
    $card = publish('awareness', ['awareness' => ['character_pose_id' => $f['watchPose'], 'awareness_type_code' => 'warning']]);
    $news = publish('news');

    $list = api('/posts')->assertOk()->json();
    validateAgainstPath($list, '/posts');
    $facts = collect($list['data'])->pluck('facts', 'id');

    expect($facts[$episode->id])->toBe(['season' => ['number' => 2, 'title' => null], 'episode_number' => 4])
        ->and($facts[$story->id]['organization'])->toMatchArray(['name' => 'ألبان الوادي', 'sector' => ['name' => 'الصناعات الغذائية', 'slug' => 'food']])
        ->and($facts[$story->id]['person_name'])->toBe('أم أحمد')
        ->and($facts[$story->id]['figures'])->toBe([['value' => '120', 'label' => 'موظفاً'], ['value' => '3', 'label' => 'فروع']])
        ->and($facts[$product->id]['product_name'])->toBe('صابون زيت الزيتون')
        ->and($facts[$product->id]['organization']['slug'])->toBe('soap-co')
        ->and($facts[$table->id])->toBe(['held_on' => '2026-09-01'])
        ->and($facts[$card->id]['type']['code'])->toBe('warning')
        ->and($facts[$card->id]['character'])->toMatchArray(['name' => 'انتبه!', 'slug' => 'watch-out', 'accent_color' => '#124FFF'])
        ->and($facts[$card->id]['pose']['image']['id'])->toBe($f['watchPicture'])
        ->and($facts[$news->id])->toBeNull();
    // The list says what the item's own page says.
    expect($facts[$story->id]['organization'])->toBe(api("/posts/{$story->id}")->json('data.details.organization'))
        ->and($facts[$card->id]['character'])->toBe(api("/posts/{$card->id}")->json('data.details.character'));
});

test('a page of posts costs the same number of queries for six items as for twelve (no query per item)', function () {
    $f = sectionFixtures();
    $round = 0;
    // Six kinds whose facts come from other tables; twice six is one full page, so every kind appears twice in it.
    $oneOfEach = function () use ($f, &$round) {
        $round++;
        publish('episode', ['episode' => ['season_id' => $f['season1'], 'episode_number' => $round]]);
        publish('story', ['story' => ['organization_id' => $f['dairy']->id, 'figures' => [['value' => '5', 'label' => 'فروع']]]]);
        publish('product', ['product' => ['product_name' => 'منتج '.$round, 'organization_id' => $f['soapCo']->id]]);
        publish('roundtable', ['roundtable' => ['held_on' => '2026-09-01']]);
        publish('awareness', ['awareness' => ['character_pose_id' => $round === 1 ? $f['raqamPose'] : $f['watchPose'], 'awareness_type_code' => 'warning']]);
        publish('news', ['sponsor_organization_id' => $round === 1 ? $f['dairy']->id : $f['soapCo']->id]);
    };
    $queries = function (int $items) {
        // One process serves both requests here, and a route keeps its controller: the presenter inside it would
        // remember the pictures of the first request.
        foreach (app('router')->getRoutes() as $route) {
            $route->controller = null;
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        expect(api('/posts')->assertOk()->json('data'))->toHaveCount($items);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $oneOfEach();
    $six = $queries(6);
    $oneOfEach();

    expect($queries(12))->toBe($six);
});

test('section pages filter by season, sector, era and character (5.9)', function () {
    $f = sectionFixtures();
    publish('episode', ['episode' => ['season_id' => $f['season1'], 'episode_number' => 1]]);
    $second = publish('episode', ['episode' => ['season_id' => $f['season2'], 'episode_number' => 1]]);
    publish('story', ['story' => ['organization_id' => $f['dairy']->id]]);
    $soapStory = publish('story', ['story' => ['organization_id' => $f['soapCo']->id]]);
    $soapProduct = publish('product', ['product' => ['product_name' => 'صابون', 'organization_id' => $f['soapCo']->id]]);
    $ottoman = Era::create(['name_ar' => 'العثماني', 'sort_order' => 1]);
    $mandate = Era::create(['name_ar' => 'الانتداب', 'sort_order' => 2]);
    $old = publish('history', ['history' => ['era_id' => $ottoman->id, 'year_from' => 1850]]);
    publish('history', ['history' => ['era_id' => $mandate->id, 'year_from' => 1930]]);
    publish('awareness', ['awareness' => ['character_pose_id' => $f['raqamPose'], 'awareness_type_code' => 'warning']]);
    $watchCard = publish('awareness', ['awareness' => ['character_pose_id' => $f['watchPose'], 'awareness_type_code' => 'advice']]);

    $ids = function (string $query) {
        $body = api('/posts?'.$query)->assertOk()->json();
        validateAgainstPath($body, '/posts');

        return array_column($body['data'], 'id');
    };

    expect($ids('section=podcast&season=2'))->toBe([$second->id])
        ->and($ids('section=success-stories&sector=soap'))->toBe([$soapStory->id])
        ->and($ids('section=made-in-nablus&sector=soap'))->toBe([$soapProduct->id])
        ->and($ids('sector=soap'))->toBe([$soapProduct->id, $soapStory->id])
        ->and($ids('section=history&order=timeline&era='.$ottoman->id))->toBe([$old->id])
        ->and($ids('section=awareness&character=watch-out'))->toBe([$watchCard->id])
        ->and($ids('season=9'))->toBe([])
        ->and($ids('sector=unknown'))->toBe([]);
    foreach (['season=0', 'season=x', 'era=-1', 'era=40000', 'sector=a%20b', 'character=a/b'] as $bad) {
        api('/posts?'.$bad)->assertStatus(422);
    }
});

test('kind takes several kinds: /news lists news, reports, opinion and infographics together', function () {
    $news = publish('news');
    $report = publish('report');
    $opinion = publish('opinion');
    $infographic = publish('infographic', ['infographic' => ['download_media_id' => $this->cover->id]]);
    publish('roundtable', ['roundtable' => ['held_on' => '2026-09-01']]);

    $body = api('/posts?kind=news,report,opinion,infographic')->assertOk()->json();
    validateAgainstPath($body, '/posts');

    expect(array_column($body['data'], 'id'))->toBe([$infographic->id, $opinion->id, $report->id, $news->id])
        ->and(array_column(api('/posts?kind=report')->json('data'), 'id'))->toBe([$report->id])
        ->and(array_column(api('/posts?kind=news,news')->json('data'), 'id'))->toBe([$news->id]);
    foreach (['news,', ',news', 'news,,report', 'news,bogus', 'NEWS', 'news%20,report', str_repeat('news,', 30).'news'] as $bad) {
        api('/posts?kind='.$bad)->assertStatus(422);
    }
});

test('the eras and each section\'s filters list only the values that have a live item, with their counts', function () {
    $f = sectionFixtures();
    $ottoman = Era::create(['name_ar' => 'العثماني', 'sort_order' => 2, 'year_from' => 1516, 'year_to' => 1917]);
    $mandate = Era::create(['name_ar' => 'الانتداب', 'sort_order' => 1]);
    $empty = Era::create(['name_ar' => 'بلا مواد', 'sort_order' => 3]);
    publish('history', ['history' => ['era_id' => $ottoman->id, 'year_from' => 1850]]);
    publish('history', ['history' => ['era_id' => $ottoman->id, 'year_from' => 1900]]);
    publish('history', ['history' => ['era_id' => $mandate->id, 'year_from' => 1930]]);
    publish('history', ['history' => ['era_id' => $empty->id], 'status' => 'draft']);
    publish('episode', ['episode' => ['season_id' => $f['season2'], 'episode_number' => 1]]);
    publish('episode', ['episode' => ['season_id' => $f['season1'], 'episode_number' => 1], 'status' => 'draft']);
    publish('story', ['story' => ['organization_id' => $f['dairy']->id]]);
    publish('awareness', ['awareness' => ['character_pose_id' => $f['raqamPose'], 'awareness_type_code' => 'warning']]);
    publish('initiative', ['initiative' => ['initiative_name' => 'مبادرة', 'organizer_organization_id' => $f['dairy']->id, 'status' => 'ended']]);

    $eras = api('/eras')->assertOk()->json();
    validateAgainstPath($eras, '/eras');
    expect($eras['data'])->toBe([
        ['id' => $mandate->id, 'name' => 'الانتداب', 'year_from' => null, 'year_to' => null, 'posts_count' => 1],
        ['id' => $ottoman->id, 'name' => 'العثماني', 'year_from' => 1516, 'year_to' => 1917, 'posts_count' => 2],
    ]);

    $filters = function (string $slug) {
        $body = api("/sections/{$slug}")->assertOk()->json();
        validateAgainstPath($body, '/sections/{slug}');

        return $body['data']['filters'];
    };
    $only = fn (string $param, string $name, array $options) => [['param' => $param, 'name' => $name, 'options' => $options]];

    expect($filters('podcast'))->toBe($only('season', 'الموسم', [['value' => '2', 'name' => 'الموسم 2', 'posts_count' => 1]]))
        ->and($filters('success-stories'))->toBe($only('sector', 'القطاع', [['value' => 'food', 'name' => 'الصناعات الغذائية', 'posts_count' => 1]]))
        ->and($filters('made-in-nablus'))->toBe($only('sector', 'القطاع', []))
        ->and($filters('history'))->toBe($only('era', 'الحقبة', [
            ['value' => (string) $mandate->id, 'name' => 'الانتداب', 'posts_count' => 1], ['value' => (string) $ottoman->id, 'name' => 'العثماني', 'posts_count' => 2]]))
        ->and($filters('initiatives'))->toBe($only('initiative_status', 'الحالة', [['value' => 'ended', 'name' => 'منتهية', 'posts_count' => 1]]))
        ->and($filters('awareness'))->toBe($only('character', 'الشخصية', [['value' => 'raqam', 'name' => 'رَقَم', 'posts_count' => 1]]))
        ->and($filters('round-table'))->toBe([])
        // The list of sections stays as it was: the filters belong to the section's own page.
        ->and(api('/sections')->json('data.0'))->not->toHaveKey('filters');
});
