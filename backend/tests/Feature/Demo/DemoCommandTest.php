<?php

use App\Demo\DemoImages;
use App\Demo\DemoManifest;
use App\Jobs\SendSiteWebhook;
use App\Jobs\SubmitIndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('removing the demo gives the database back as raqam:install left it', function () {
    $before = installedState();

    $this->artisan('raqam:demo', ['--posts' => 11, '--seed' => 3])->assertSuccessful();
    expect(installedState())->not->toBe($before);   // services shown, pages filled, a playlist on the home page

    $this->artisan('raqam:demo', ['--remove' => true, '--force' => true])->assertSuccessful();

    expect(installedState())->toEqual($before);
    foreach (['posts', 'videos', 'authors', 'guests', 'organizations', 'sectors', 'eras', 'tags', 'media_assets', 'playlists', 'podcast_seasons',
        'advertisers', 'ad_campaigns', 'house_ads', 'character_poses', 'menu_items', 'faqs', 'exchange_rates', 'weather_observations', 'weather_forecasts'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is empty again");
    }
    expect(Storage::disk('media_local_public')->allFiles())->toBe([])->and(is_file($this->manifestPath))->toBeFalse();
});
