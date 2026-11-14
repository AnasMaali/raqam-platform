<?php

use App\Demo\DemoImages;
use App\Demo\DemoManifest;
use App\Demo\DemoSeeder;
use App\Jobs\SendSiteWebhook;
use App\Jobs\SubmitIndexNow;
use App\Support\ArabicNormalizer;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// The prices the demo gives the services, in minor units: only obviously made-up ones (see MadeUpPricesTest).
const DEMO_MADE_UP_MINOR = [123456, 23456, 12345, 9999, 11111, 13570];

beforeEach(function () {
    Storage::fake('media_local_public');
    // Small pictures keep the run fast; banners keep their slot sizes whatever the scale.
    $this->app->instance(DemoImages::class, new DemoImages(scale: 0.12));
    $this->manifestPath = storage_path('framework/testing/demo-manifest-'.bin2hex(random_bytes(4)).'.json');
    $this->app->instance(DemoManifest::class, new DemoManifest($this->manifestPath));

    $this->artisan('raqam:install', ['--no-migrate' => true])->assertSuccessful();
    insertUser('staff', 'admin@example.org');
});

afterEach(function () {
    @unlink($this->manifestPath);
});

/** A manifest as an earlier run would have left it. */
function demoManifest(array $rows = [], array $checksums = []): DemoManifest
{
    $manifest = app(DemoManifest::class);
    foreach ($rows as $table => $keys) {
        $manifest->add($table, $keys);
    }
    $manifest->save();
    if ($checksums !== []) {
        // Into the file as a run writes them, then read back.
        $data = json_decode(file_get_contents($manifest->path()), true);
        $data['checksums'] = $checksums;
        file_put_contents($manifest->path(), json_encode($data));
    }

    return $manifest->load();
}

function demoAuthor(string $name): int
{
    return DB::table('authors')->insertGetId(['name' => $name, 'slug' => 'author-'.bin2hex(random_bytes(3))]);
}

/** The rows raqam:install wrote that the demo changes and must give back, without times and ids that a rewrite renews. */
function installedState(): array
{
    return [
        'services' => DB::table('services')->orderBy('id')->get(['id', 'slug', 'price_mode', 'price_amount', 'currency_code', 'price_note', 'sale_price_amount', 'sale_ends_at', 'cover_media_id', 'is_active'])->toArray(),
        'deliverables' => DB::table('service_deliverables')->orderBy('service_id')->orderBy('sort_order')->get()->toArray(),
        'pages' => DB::table('pages')->orderBy('id')->get(['id', 'title', 'slug', 'body', 'is_published'])->toArray(),
        'home' => DB::table('home_blocks')->orderBy('sort_order')->get(['id', 'block_type', 'category_id', 'section_id', 'playlist_id', 'placement_code', 'item_limit', 'sort_order', 'is_active'])->toArray(),
        'placements' => DB::table('ad_placements')->orderBy('code')->get()->toArray(),
        'characters' => DB::table('characters')->orderBy('id')->get(['id', 'name_ar', 'slug', 'accent_color', 'is_active'])->toArray(),
        'bot' => DB::table('bot_entries')->orderBy('id')->get(['id', 'faq_id', 'question'])->toArray(),
        'cities' => DB::table('weather_locations')->orderBy('city_id')->get()->toArray(),
    ];
}

test('it never runs in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('raqam:demo', ['--posts' => 11])->assertFailed()->expectsOutputToContain('never runs in production');

    expect(DB::table('posts')->count())->toBe(0)->and(DB::table('media_assets')->count())->toBe(0);
});

test('it refuses a database that already has content of its own', function () {
    demoAuthor('كاتب حقيقي');

    $this->artisan('raqam:demo', ['--posts' => 11])->assertFailed()
        ->expectsOutputToContain('already has content: authors (1)')->expectsOutputToContain('fills an empty database only');

    expect(DB::table('posts')->count())->toBe(0)->and(DB::table('authors')->count())->toBe(1);
});

test('--fresh asks before removing, and without a terminal it refuses unless --force', function () {
    $author = demoAuthor('كاتب تجريبي');
    demoManifest(['authors' => [$author]]);

    $this->artisan('raqam:demo', ['--fresh' => true, '--no-interaction' => true])->assertFailed()
        ->expectsOutputToContain('Not removing without confirmation');
    $this->artisan('raqam:demo', ['--remove' => true])->expectsConfirmation('Delete these rows?', 'no')->assertFailed();

    expect(DB::table('authors')->where('id', $author)->exists())->toBeTrue();

    $this->artisan('raqam:demo', ['--remove' => true, '--force' => true])->assertSuccessful()->expectsOutputToContain('Removed: authors (1)');
    expect(DB::table('authors')->count())->toBe(0)->and(is_file($this->manifestPath))->toBeFalse();
});

test('it never removes rows it did not write', function () {
    $ours = demoAuthor('كاتب تجريبي');
    $theirs = demoAuthor('كاتب حقيقي');
    demoManifest(['authors' => [$ours]]);

    $this->artisan('raqam:demo', ['--fresh' => true, '--force' => true])->assertFailed()
        ->expectsOutputToContain('content the demo did not write: authors (1)');

    expect(DB::table('authors')->whereIn('id', [$ours, $theirs])->count())->toBe(2)->and(DB::table('posts')->count())->toBe(0);
});

test('a record that no longer matches the database removes nothing', function () {
    $media = insertMedia();
    demoManifest(['media_assets' => [$media]], [$media => str_repeat('0', 64)]);

    $this->artisan('raqam:demo', ['--remove' => true, '--force' => true])->assertFailed()->expectsOutputToContain('does not match this database');

    expect(DB::table('media_assets')->where('id', $media)->exists())->toBeTrue();
});

test('a small run fills every part of the site the API serves, quietly', function () {
    Queue::fake();
    config(['raqam.site_webhook.url' => 'https://site.example/hook', 'raqam.site_webhook.secret' => 'test-secret', 'raqam.indexnow.key' => str_repeat('a', 32)]);

    $this->artisan('raqam:demo', ['--posts' => 24, '--seed' => 7])->assertSuccessful()->expectsOutputToContain('Done in');

    // The outside world heard nothing while it ran (the site and IndexNow are told by the editor's saves, not by a fill-in).
    Queue::assertNotPushed(SendSiteWebhook::class);
    Queue::assertNotPushed(SubmitIndexNow::class);
    expect(config('raqam.site_webhook.url'))->toBe('https://site.example/hook')->and(config('queue.default'))->toBe('sync');

    // Every kind, published, in the past year; a couple breaking, some featured.
    $kinds = DB::table('posts')->groupBy('kind_code')->selectRaw('kind_code, count(*) AS n')->pluck('n', 'kind_code')->map(fn ($n) => (int) $n)->all();
    expect(array_keys($kinds))->toEqualCanonicalizing(DB::table('post_kinds')->pluck('code')->all())
        ->and(array_sum($kinds))->toBe(24)
        ->and(DB::table('posts')->where('status', '<>', 'published')->count())->toBe(0)
        ->and(DB::table('posts')->where('published_at', '>', now())->count())->toBe(0)
        ->and(DB::table('posts')->where('breaking_until', '>', now())->count())->toBe(2)
        ->and(DB::table('posts')->where('is_featured', true)->count())->toBeGreaterThan(0);

    // Every picture has its sizes.
    $withoutSizes = DB::table('media_assets')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('media_variants')->whereColumn('media_variants.media_id', 'media_assets.id'))->count();
    expect(DB::table('media_assets')->count())->toBeGreaterThan(50)->and($withoutSizes)->toBe(0);

    $total = api('/posts')->assertOk()->json('meta.total');
    expect($total)->toBe(24);
    foreach (DB::table('posts')->orderBy('id')->get(['id', 'kind_code'])->unique('kind_code') as $post) {
        $response = api("/posts/{$post->id}")->assertOk();
        // Decoded as objects, so an empty map ({} of links) stays a map for the contract check.
        validateAgainstPath(['data' => json_decode($response->getContent())->data], '/posts/{id}');
        $item = $response->json('data');
        if (! in_array($post->kind_code, ['news', 'report', 'opinion'], true)) {
            expect($item['details'])->not->toBeNull("{$post->kind_code} has its facts");
        }
    }

    $response = api('/home')->assertOk();
    validateAgainstPath(['data' => json_decode($response->getContent())->data], '/home');
    $home = $response->json('data');
    $types = collect($home)->pluck('type')->all();
    foreach (['hero', 'breaking', 'latest', 'category', 'most_read', 'videos', 'playlist', 'section', 'awareness', 'markets'] as $type) {
        expect($types)->toContain($type);
    }

    expect(api('/currencies')->assertOk()->json('data'))->toHaveCount(DB::table('rate_board_currencies')->where('is_active', true)->count())
        ->and(api('/currencies/USD/history')->assertOk()->json('data'))->not->toBeEmpty();
    $weather = api('/weather/widget')->assertOk()->json('data');
    expect($weather)->toHaveCount(12)->and(collect($weather)->every(fn ($c) => $c['now'] !== null && $c['now']['stale'] === false))->toBeTrue()
        ->and(api('/weather/'.$weather[0]['slug'])->assertOk()->json('data.forecast'))->toHaveCount(5);

    $slots = collect(api('/ads')->assertOk()->json('data'));
    expect($slots->where('active', true)->every(fn ($s) => $s['ads'] !== []))->toBeTrue();

    $services = collect(api('/services')->assertOk()->json('data.groups'))->flatMap(fn ($g) => $g['services']);
    expect($services)->toHaveCount(6);
    $amounts = DB::table('services')->whereNotNull('price_amount')->pluck('price_amount')->merge(DB::table('services')->whereNotNull('sale_price_amount')->pluck('sale_price_amount'));
    expect($amounts)->not->toBeEmpty()->and($amounts->every(fn ($a) => in_array((int) $a, DEMO_MADE_UP_MINOR, true)))->toBeTrue();

    expect(api('/faqs')->assertOk()->json('data'))->toHaveCount(12)
        ->and(api('/menus')->assertOk()->json('data.header'))->not->toBeEmpty()
        ->and(api('/sections/podcast')->assertOk()->json('data.key'))->toBe('podcast')
        ->and(api('/team')->assertOk()->json('data'))->not->toBeEmpty()
        ->and(api('/pages/about')->assertOk()->json('data.title'))->not->toBeEmpty()
        ->and(api('/posts/most-read')->assertOk()->json('data'))->not->toBeEmpty();
    $videos = api('/videos')->assertOk()->json('data');
    expect($videos)->not->toBeEmpty()->and(DB::table('youtube_videos')->where('is_short', true)->count())->toBeGreaterThan(0);
    $characters = api('/characters')->assertOk()->json('data');
    expect(collect($characters)->every(fn ($c) => count($c['poses']) === DB::table('pose_types')->count()))->toBeTrue();

    expect(json_decode(file_get_contents($this->manifestPath), true)['rows']['posts'])->toHaveCount(24);
});

test('the readers\' side: subscribers, messages, chat conversations and 90 days of statistics that add up', function () {
    $this->artisan('raqam:demo', ['--posts' => 40, '--seed' => 11])->assertSuccessful()->expectsOutputToContain('days of statistics');
    $today = now('Asia/Hebron')->startOfDay();
    $first = $today->copy()->subDays(89)->toDateString();

    // Readers in every state, each with its consent proof; the inbox has new and handled messages, oldest first.
    $statuses = DB::table('subscribers')->groupBy('status')->selectRaw('status, count(*) AS n')->pluck('n', 'status')->all();
    expect(array_sum($statuses))->toBe(30)->and(array_keys($statuses))->toContain('active', 'pending')
        ->and(DB::table('subscribers')->whereNull('consent_ip_hash')->count())->toBe(0)
        ->and(DB::table('subscribers')->where('email', 'not like', '%@example.org')->count())->toBe(0);
    $inbox = DB::table('contact_messages')->orderBy('id')->get();
    expect($inbox)->toHaveCount(6)->and($inbox->where('status', 'new'))->not->toBeEmpty()
        ->and($inbox->where('status', '<>', 'new')->every(fn ($m) => $m->handled_by !== null && $m->handled_at >= $m->created_at))->toBeTrue()
        ->and($inbox->pluck('created_at')->all())->toBe($inbox->pluck('created_at')->sort()->values()->all());

    // Conversations of one to three turns, a question and its answer each; some found an answer, some did not.
    $conversations = DB::table('bot_conversations')->count();
    $turns = DB::table('bot_messages')->groupBy('conversation_id')->selectRaw("count(*) FILTER (WHERE role = 'user') AS q, count(*) FILTER (WHERE role = 'bot') AS a")->get();
    expect($conversations)->toBe(10)->and($turns)->toHaveCount(10)
        ->and($turns->every(fn ($t) => $t->q === $t->a && $t->q >= 1 && $t->q <= 3))->toBeTrue()
        ->and(DB::table('bot_messages')->where('role', 'user')->whereNotNull('matched_entry_id')->count())->toBeGreaterThan(0)
        ->and(DB::table('bot_messages')->where('role', 'user')->whereNull('matched_entry_id')->count())->toBeGreaterThan(0);

    // 90 days, today included; each day's page views hold the posts' own, and its visitors split exactly into devices and sources.
    $days = DB::table('site_stats_daily')->orderBy('stat_date')->pluck('stat_date')->all();
    expect($days)->toHaveCount(90)->and($days[0])->toBe($first)->and(end($days))->toBe($today->toDateString());
    $broken = DB::select(<<<'SQL'
        SELECT s.stat_date FROM site_stats_daily s
        WHERE s.page_views < coalesce((SELECT sum(views) FROM post_views_daily p WHERE p.stat_date = s.stat_date), 0)
           OR s.unique_visitors <> (SELECT sum(visits) FROM device_stats_daily d WHERE d.stat_date = s.stat_date)
           OR s.unique_visitors <> (SELECT sum(visits) FROM traffic_sources_daily t WHERE t.stat_date = s.stat_date)
        SQL);
    expect($broken)->toBe([])
        ->and(DB::table('post_views_daily')->where('stat_date', '<', $first)->count())->toBe(0)
        ->and(DB::table('post_views_daily')->min('stat_date'))->toBeLessThan($today->copy()->subDays(30)->toDateString())
        ->and(DB::table('event_stats_daily')->distinct()->count('event_code'))->toBe(DB::table('event_types')->count());

    // Searches, some of which find nothing; the terms are stored normalised.
    expect(DB::table('search_stats_daily')->whereColumn('zero_result_searches', 'searches')->count())->toBeGreaterThan(0)
        ->and(DB::table('search_terms')->pluck('normalized_term')->every(fn ($t) => $t === ArabicNormalizer::normalize($t)))->toBeTrue();

    // Ad views only on the days a campaign ran, in its own places; contacts, plays and missing pages too.
    $outside = DB::select(<<<'SQL'
        SELECT 1 FROM ad_stats_daily s JOIN ad_campaigns c ON c.id = s.campaign_id
        WHERE s.stat_date < (c.starts_at AT TIME ZONE 'Asia/Hebron')::date OR s.stat_date > (c.ends_at AT TIME ZONE 'Asia/Hebron')::date
           OR NOT EXISTS (SELECT 1 FROM ad_campaign_placements p WHERE p.campaign_id = s.campaign_id AND p.placement_code = s.placement_code)
        SQL);
    foreach (['site_stats_daily', 'post_views_daily', 'ad_stats_daily', 'search_stats_daily', 'service_contact_clicks_daily', 'video_plays_daily'] as $table) {
        expect(DB::table($table)->max('stat_date'))->toBeLessThanOrEqual($today->toDateString(), "{$table} has no day after today");
    }
    expect(DB::table('ad_stats_daily')->count())->toBeGreaterThan(0)->and($outside)->toBe([])
        ->and(DB::table('ad_stats_daily')->whereColumn('clicks', '>', 'impressions')->count())->toBe(0)
        ->and(DB::table('service_contact_clicks_daily')->count())->toBeGreaterThan(0)
        ->and(DB::table('video_plays_daily')->count())->toBeGreaterThan(0)
        ->and(DB::table('not_found_paths')->count())->toBe(12);
});

test('statistics the site counted itself stay as they are, and stay when the demo is removed', function () {
    $today = now('Asia/Hebron')->toDateString();
    DB::table('site_stats_daily')->insert(['stat_date' => $today, 'page_views' => 7, 'unique_visitors' => 3]);
    DB::table('search_terms')->insert(['normalized_term' => ArabicNormalizer::normalize('سعر الدولار')]);
    DB::table('not_found_paths')->insert(['path' => '/news/old', 'hits' => 2]);

    $this->artisan('raqam:demo', ['--posts' => 11, '--seed' => 5])->assertSuccessful();
    expect((array) DB::table('site_stats_daily')->where('stat_date', $today)->first(['page_views', 'unique_visitors']))->toBe(['page_views' => 7, 'unique_visitors' => 3])
        ->and(DB::table('not_found_paths')->where('path', '/news/old')->value('hits'))->toBe(2)
        ->and(DB::table('site_stats_daily')->count())->toBe(90);

    $this->artisan('raqam:demo', ['--remove' => true, '--force' => true])->assertSuccessful();
    expect(DB::table('site_stats_daily')->pluck('stat_date')->all())->toBe([$today])
        ->and(DB::table('search_terms')->count())->toBe(1)
        ->and(DB::table('not_found_paths')->pluck('path')->all())->toBe(['/news/old']);
});

test('a long run commits a batch of posts at a time (one transaction for 2000 posts runs out of locks)', function () {
    $this->app->resolving(DemoSeeder::class, fn ($seeder) => $seeder->batch = 10);
    // The run's own transactions are the ones opened one level above the test's (RefreshDatabase holds one open).
    $outer = DB::transactionLevel() + 1;
    $postsWhenOpened = [];
    Event::listen(TransactionBeginning::class, function () use ($outer, &$postsWhenOpened) {
        if (DB::transactionLevel() === $outer && DB::table('posts')->count() < 24) {
            $postsWhenOpened[] = DB::table('posts')->count();
        }
    });

    $this->artisan('raqam:demo', ['--posts' => 24, '--seed' => 7])->assertSuccessful();

    expect(array_values(array_unique($postsWhenOpened)))->toBe([0, 10, 20])->and(DB::table('posts')->count())->toBe(24);
});

test('a run that fails after a batch was committed leaves nothing behind, files included', function () {
    $this->app->resolving(DemoSeeder::class, fn ($seeder) => $seeder->batch = 10);
    $before = installedState();
    // The FAQ comes after every post: two batches are committed by then.
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into "faqs"')) {
            throw new RuntimeException('the run stops here');
        }
    });

    expect(fn () => $this->artisan('raqam:demo', ['--posts' => 24, '--seed' => 7])->run())->toThrow(RuntimeException::class, 'the run stops here');

    expect(installedState())->toEqual($before);
    foreach (['posts', 'videos', 'authors', 'guests', 'media_assets', 'playlists', 'advertisers', 'ad_campaigns', 'exchange_rates', 'post_views_daily'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is empty again");
    }
    expect(Storage::disk('media_local_public')->allFiles())->toBe([])->and(is_file($this->manifestPath))->toBeFalse();
});

test('removing the demo gives the database back as raqam:install left it', function () {
    $before = installedState();

    $this->artisan('raqam:demo', ['--posts' => 11, '--seed' => 3])->assertSuccessful();
    expect(installedState())->not->toBe($before);   // services shown, pages filled, a playlist on the home page

    $this->artisan('raqam:demo', ['--remove' => true, '--force' => true])->assertSuccessful();

    expect(installedState())->toEqual($before);
    foreach (['posts', 'videos', 'authors', 'guests', 'organizations', 'sectors', 'eras', 'tags', 'media_assets', 'playlists', 'podcast_seasons',
        'advertisers', 'ad_campaigns', 'house_ads', 'character_poses', 'menu_items', 'faqs', 'exchange_rates', 'weather_observations', 'weather_forecasts',
        'subscribers', 'contact_messages', 'bot_conversations', 'bot_messages', 'site_stats_daily', 'traffic_sources_daily', 'device_stats_daily',
        'event_stats_daily', 'search_terms', 'search_stats_daily', 'post_views_daily', 'ad_stats_daily', 'service_contact_clicks_daily',
        'video_plays_daily', 'not_found_paths'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is empty again");
    }
    expect(Storage::disk('media_local_public')->allFiles())->toBe([])->and(is_file($this->manifestPath))->toBeFalse();
});
