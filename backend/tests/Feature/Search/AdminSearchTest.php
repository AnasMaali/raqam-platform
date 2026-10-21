<?php

use App\Filament\Pages\SearchStatistics;
use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\Posts\PostResource;
use App\Models\User;
use App\Search\SearchStats;
use App\Search\SearchStatsReport;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    ensurePostKinds();
    $this->actingAs(User::factory()->staff()->create());
});

// ---- statistics (10.14) ---------------------------------------------------------------------------

function countSearch(string $term, string $day, int $searches, int $zero): void
{
    DB::table('search_terms')->insertOrIgnore(['normalized_term' => $term]);
    DB::table('search_stats_daily')->insert(['term_id' => DB::table('search_terms')->where('normalized_term', $term)->value('id'),
        'stat_date' => $day, 'searches' => $searches, 'zero_result_searches' => $zero]);
}

test('the report sums the period: most searched words, and words that found nothing', function () {
    $today = now('Asia/Hebron');
    countSearch('اسعار الذهب', $today->toDateString(), 5, 0);
    countSearch('اسعار الذهب', $today->copy()->subDays(3)->toDateString(), 4, 1);
    countSearch('بنزين', $today->toDateString(), 2, 2);
    countSearch('قديم', $today->copy()->subDays(20)->toDateString(), 50, 50);

    $week = SearchStatsReport::for('7');
    $month = SearchStatsReport::for('30');

    expect(array_map(fn ($r) => [$r->term, $r->searches, $r->zero], $week->top))->toBe([['اسعار الذهب', 9, 1], ['بنزين', 2, 2]])
        ->and(array_map(fn ($r) => $r->term, $week->nothingFound))->toBe(['بنزين', 'اسعار الذهب'])
        ->and([$week->searches, $week->zero])->toBe([11, 3])
        ->and($month->top[0]->term)->toBe('قديم')
        ->and(SearchStatsReport::for('1')->top[0]->searches)->toBe(5);
});

test('a custom period is used as given; reversed or longer than a year is corrected and said', function () {
    countSearch('ذهب', '2026-03-10', 3, 0);

    expect(SearchStatsReport::for('custom', '2026-03-01', '2026-03-31')->searches)->toBe(3)
        ->and(SearchStatsReport::for('custom', '2026-03-31', '2026-03-01')->problem)->toContain('بُدّلتا')
        ->and(SearchStatsReport::for('custom', '2026-03-31', '2026-03-01')->searches)->toBe(3)
        ->and(SearchStatsReport::for('custom', '2020-01-01', '2026-03-31')->problem)->toContain('آخر سنة')
        ->and(SearchStatsReport::for('custom', 'garbage', ['x'])->problem)->toBeNull()
        ->and(SearchStatsReport::for('weird')->from)->toBe(now('Asia/Hebron')->subDays(6)->toDateString());
});

test('the page shows the figures and follows the chosen period', function () {
    countSearch('اسعار الذهب', now('Asia/Hebron')->toDateString(), 7, 0);
    countSearch('كلمة قديمة', now('Asia/Hebron')->subDays(15)->toDateString(), 3, 3);

    Livewire::test(SearchStatistics::class)->assertOk()
        ->assertSee('اسعار الذهب')->assertDontSee('كلمة قديمة')
        ->set('data.period', '30')->assertSee('كلمة قديمة');
});

test('a term is escaped on the page', function () {
    SearchStats::record('<img src=x onerror=alert(1)> ذهب', false);

    Livewire::test(SearchStatistics::class)->assertDontSeeHtml('<img src=x')->assertSeeHtml('&lt;img src=x');
});

// ---- quick search in the panel (10.15) --------------------------------------------------------------

test('the panel finds items (drafts too), pictures and writers, whatever the hamza or ta marbuta', function () {
    $draft = insertPost(['title' => 'أسعار الطاقة الشمسية']);
    $published = insertPost(publishedPostValues(['title' => 'مسودة أخرى', 'excerpt' => 'إعلان عن الطاقه']));
    $picture = insertMedia(['original_name' => 'photo.jpg', 'alt_text' => 'مزرعة الطاقة']);
    $author = DB::table('authors')->insertGetId(['name' => 'سلمى الأحمد', 'slug' => 'salma']);

    $titles = fn (string $resource, string $q) => $resource::getGlobalSearchResults($q)->map(fn ($r) => (string) $r->title)->all();

    expect($titles(PostResource::class, 'اسعار الطاقه'))->toBe(['أسعار الطاقة الشمسية'])
        ->and($titles(PostResource::class, 'الطاقة'))->toHaveCount(2)
        ->and($titles(MediaAssetResource::class, 'مزرعه'))->toBe(['photo.jpg'])
        ->and($titles(AuthorResource::class, 'الاحمد'))->toBe(['سلمى الأحمد'])
        ->and($titles(PostResource::class, '%'))->toBe([])
        ->and($titles(PostResource::class, '   '))->toBe([]);
});

test('the search box of the panel answers with those results', function () {
    insertPost(['title' => 'أسعار الذهب اليوم']);

    Livewire::test(GlobalSearch::class)->set('search', 'اسعار')->assertSee('أسعار الذهب اليوم');
});
