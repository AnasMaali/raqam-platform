<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HomeLayoutEditor;
use App\Filament\Pages\MenuEditor;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Resources\Organizations\Pages\ManageOrganizations;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The panel's screens ask the database the same number of times however many rows they show (QA-5,
 * docs/qa/performance.md): each screen is opened with a few rows, then with more, and the two must cost the same.
 * A query per row, or per item of a list being edited, makes the second number larger.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/builders.php';

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    ensurePostKinds();
    $this->actingAs(User::factory()->staff()->create());
});

/** The number of database queries that opening a screen makes ($open draws it). */
function screenQueries(Closure $open): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $open();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

/** Opens the screen with what $add made, then again after $add made more; both must cost the same. */
function sameScreenQueriesWithMore(string $name, Closure $open, Closure $add, int $first = 2, int $more = 4): void
{
    for ($i = 0; $i < $first; $i++) {
        $add($i);
    }
    $open();   // a first opening, so what the panel reads once in a test's life (its navigation, the settings) is read before both
    $few = screenQueries($open);
    for ($i = $first; $i < $first + $more; $i++) {
        $add($i);
    }
    $many = screenQueries($open);

    expect($many)->toBe($few, "{$name}: {$few} queries with {$first} rows, {$many} with ".($first + $more));
}

/** A published news item with a cover that has its thumbnail. */
function panelPost(int $i): int
{
    $cover = insertMedia();
    DB::table('media_variants')->insert(['media_id' => $cover, 'variant' => 'thumb', 'format' => 'jpeg', 'path' => "test/thumb-{$i}.jpg", 'width' => 160, 'height' => 120, 'size_bytes' => 100]);

    return insertPost(publishedPostValues(['cover_media_id' => $cover, 'title' => "خبر للوحة {$i}", 'slug' => "خبر-للوحة-{$i}"]));
}

test('the home, with more drafts, scheduled items and new messages', function () {
    // At most five of each are listed: 1 then 4 keeps every list growing.
    sameScreenQueriesWithMore('home', fn () => Livewire::test(Dashboard::class)->assertOk(), function (int $i) {
        insertPost(['kind_code' => 'news', 'status' => 'draft', 'title' => "مسودة {$i}", 'slug' => "مسودة-{$i}"]);
        insertPost(publishedPostValues(['status' => 'scheduled', 'published_at' => now()->addDays($i + 1), 'title' => "مجدول {$i}", 'slug' => "مجدول-{$i}"]));
        DB::table('contact_messages')->insert(['name' => "زائر {$i}", 'email' => "v{$i}@example.com", 'subject' => "سؤال {$i}", 'message' => 'نص الرسالة']);
    }, first: 1, more: 3);
});

test('the list of posts, with more posts', function () {
    sameScreenQueriesWithMore('posts', fn () => Livewire::test(ListPosts::class)->assertOk(), fn (int $i) => panelPost($i));
});

test('the media library, with more pictures', function () {
    sameScreenQueriesWithMore('media', fn () => Livewire::test(ListMediaAssets::class)->assertOk(), function (int $i) {
        $id = insertMedia();
        DB::table('media_variants')->insert(['media_id' => $id, 'variant' => 'thumb', 'format' => 'jpeg', 'path' => "test/m-thumb-{$i}.jpg", 'width' => 160, 'height' => 120, 'size_bytes' => 100]);
    });
});

test('a picture used by more posts, as their cover and in their galleries', function () {
    $picture = insertMedia();
    sameScreenQueriesWithMore('media edit', fn () => Livewire::test(EditMediaAsset::class, ['record' => $picture])->assertOk(), function (int $i) use ($picture) {
        $post = insertPost(publishedPostValues(['cover_media_id' => $picture, 'title' => "يستعمل الصورة {$i}", 'slug' => "يستعمل-{$i}"]));
        DB::table('post_gallery_items')->insert(['post_id' => $post, 'media_id' => $picture, 'sort_order' => 1]);
    });
});

test('the characters, with more characters', function () {
    sameScreenQueriesWithMore('characters', fn () => Livewire::test(ListCharacters::class)->assertOk(), fn (int $i) => growthCharacter($i));
});

test('the currencies, with more of them on the board', function () {
    ensureCurrencies();
    DB::table('rate_board_currencies')->delete();
    $codes = DB::table('currencies')->where('code', '<>', 'ILS')->orderBy('code')->pluck('code');   // the shekel is the base, never on the board
    sameScreenQueriesWithMore('currencies', fn () => Livewire::test(ManageCurrencies::class)->assertOk(),
        fn (int $i) => DB::table('rate_board_currencies')->insert(['currency_code' => $codes[$i], 'sort_order' => $i + 1, 'is_active' => true]));
});

test('the weather cities, with more cities', function () {
    ensureCurrencies();
    DB::table('weather_locations')->delete();
    $cities = DB::table('cities')->orderBy('id')->pluck('id');
    $condition = DB::table('weather_conditions')->value('code');
    sameScreenQueriesWithMore('cities', fn () => Livewire::test(ManageCities::class)->assertOk(), function (int $i) use ($cities, $condition) {
        DB::table('weather_locations')->insert(['city_id' => $cities[$i], 'is_active' => true, 'sort_order' => $i + 1, 'is_default' => $i === 0]);
        DB::table('weather_observations')->insert(['city_id' => $cities[$i], 'provider_code' => 'openweathermap', 'observed_at' => now()->subMinutes(5), 'temperature_c' => 20, 'condition_code' => $condition, 'is_day' => true]);
    });
});

test('a playlist with more videos', function () {
    $playlist = DB::table('playlists')->insertGetId(['name_ar' => 'قائمة', 'slug' => 'قائمة']);
    sameScreenQueriesWithMore('playlist edit', fn () => Livewire::test(EditPlaylist::class, ['record' => $playlist])->assertOk(), function (int $i) use ($playlist) {
        $video = DB::table('videos')->insertGetId(['source' => 'youtube']);
        DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => sprintf('pe%09d', $i), 'source_title' => "حلقة {$i}"]);
        DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => $i + 1]);
    });
});

test('the list of playlists, with more playlists', function () {
    sameScreenQueriesWithMore('playlists', fn () => Livewire::test(ListPlaylists::class)->assertOk(), function (int $i) {
        $playlist = DB::table('playlists')->insertGetId(['name_ar' => "قائمة {$i}", 'slug' => "قائمة-{$i}", 'sort_order' => $i + 1]);
        $video = DB::table('videos')->insertGetId(['source' => 'youtube']);
        DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => sprintf('pl%09d', $i), 'source_title' => "حلقة {$i}"]);
        DB::table('playlist_videos')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'sort_order' => 1]);
    });
});

test('a round table being edited, with more guests', function () {
    $post = growthPost('roundtable', fn (int $p) => DB::table('roundtable_sessions')->insert(['post_id' => $p, 'kind_code' => 'roundtable', 'held_on' => '2026-10-01']));
    sameScreenQueriesWithMore('post edit', fn () => Livewire::test(EditPost::class, ['record' => $post])->assertOk(),
        fn (int $i) => DB::table('post_guests')->insert(['post_id' => $post, 'guest_id' => growthGuest($i), 'role_code' => 'guest', 'sort_order' => $i + 1]));
});

test('a post being edited, with more pictures in its gallery', function () {
    $post = growthPost('news');
    sameScreenQueriesWithMore('gallery', fn () => Livewire::test(EditPost::class, ['record' => $post])->assertOk(),
        fn (int $i) => DB::table('post_gallery_items')->insert(['post_id' => $post, 'media_id' => insertMedia(), 'sort_order' => $i + 1]));
});

test('the home page layout, with more blocks', function () {
    $category = insertCategory('تصنيف-الرئيسية');
    $section = DB::table('sections')->where('key', 'podcast')->value('id');
    DB::table('home_blocks')->delete();
    sameScreenQueriesWithMore('home layout', fn () => Livewire::test(HomeLayoutEditor::class)->assertOk(), fn (int $i) => DB::table('home_blocks')->insert(
        ['block_type' => $i % 2 ? 'category' : 'section', 'category_id' => $i % 2 ? $category : null, 'section_id' => $i % 2 ? null : $section, 'sort_order' => $i + 1, 'is_active' => true]));
});

test('the menus, with more items', function () {
    $menu = DB::table('menus')->where('key', 'header')->value('id') ?? DB::table('menus')->insertGetId(['key' => 'header', 'name_ar' => 'رأس الموقع']);
    DB::table('menu_items')->where('menu_id', $menu)->delete();
    sameScreenQueriesWithMore('menus', fn () => Livewire::test(MenuEditor::class)->assertOk(),
        // Items of every kind: an item pointing at a page, a category or a section draws that kind's picker.
        fn (int $i) => DB::table('menu_items')->insert(['menu_id' => $menu, 'label_ar' => "عنصر {$i}", 'sort_order' => $i + 1] + match ($i % 3) {
            0 => ['page_id' => DB::table('pages')->insertGetId(['title' => "صفحة {$i}", 'slug' => "صفحة-{$i}", 'body' => '<p>نص</p>'])],
            1 => ['category_id' => insertCategory("تصنيف-قائمة-{$i}")],
            default => ['section_id' => DB::table('sections')->orderBy('id')->value('id')],
        }), 3, 3);
});

test('the companies, with more of them', function () {
    sameScreenQueriesWithMore('organizations', fn () => Livewire::test(ManageOrganizations::class)->assertOk(), fn (int $i) => growthOrganization($i));
});

test('the services, with more of them', function () {
    sameScreenQueriesWithMore('services', fn () => Livewire::test(ListServices::class)->assertOk(),
        fn (int $i) => insertService(['slug' => "خدمة-لوحة-{$i}", 'currency_code' => $i % 2 ? 'USD' : 'JOD']));
});

test('the subscribers screen counts the months of its chart in one query, not three for each month', function () {
    $asked = array_column((function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListSubscribers::class)->assertOk();
        DB::disableQueryLog();

        return DB::getQueryLog();
    })(), 'query');

    expect(count(preg_grep('/from\s+"?subscribers"?/i', $asked)))->toBeLessThanOrEqual(4);
});

test('opening the e-mail templates writes nothing once each template has its row', function () {
    Livewire::test(ListEmailTemplates::class)->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListEmailTemplates::class)->assertOk();
    $asked = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(preg_grep('/^insert/i', $asked))->toBe([]);
});
