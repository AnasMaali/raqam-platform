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
        '/newsletter/unsubscribe', '/newsletter/brevo-webhook', '/chat/messages', '/stats/hits', '/bookings', '/bookings/track'];
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
