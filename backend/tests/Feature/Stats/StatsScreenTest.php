<?php

use App\Analytics\StatsKeys;
use App\Analytics\StatsReport;
use App\Content\RedirectManager;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\NotFoundPaths\NotFoundPathResource;
use App\Filament\Resources\NotFoundPaths\Pages\ListNotFoundPaths;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Models\NotFoundPath;
use App\Models\Redirect;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    config(['raqam.api.site_token' => STATS_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example']);
    clearStatsKeys();
});

afterEach(fn () => clearStatsKeys());

function daysAgo(int $n): string
{
    return now('Asia/Hebron')->subDays($n)->toDateString();
}

/** A week of stored figures: two posts in two categories, one in a section, with authors. */
function storedWeek(): array
{
    ensurePostKinds();
    $economy = insertCategory('اقتصاد');
    $banks = insertCategory('بنوك', $economy);
    $a = insertPost(publishedPostValues(['title' => 'خبر البنوك', 'category_id' => $banks, 'published_at' => now()->subDays(20)]));
    $b = insertPost(publishedPostValues(['title' => 'خبر الأسواق', 'category_id' => $economy, 'published_at' => now()->subDays(20)]));
    $story = insertPost(['kind_code' => 'story', 'title' => 'قصة نجاح', 'slug' => 'story-'.bin2hex(random_bytes(3)), 'status' => 'draft']);
    $author = DB::table('authors')->insertGetId(['name' => 'سلمى', 'slug' => 'salma']);
    DB::table('post_authors')->insert(['post_id' => $a, 'author_id' => $author, 'sort_order' => 1]);

    DB::table('site_stats_daily')->insert([
        ['stat_date' => daysAgo(1), 'page_views' => 120, 'unique_visitors' => 40],
        ['stat_date' => daysAgo(3), 'page_views' => 80, 'unique_visitors' => 30],
        ['stat_date' => daysAgo(9), 'page_views' => 50, 'unique_visitors' => 20],   // the 7 days before
        ['stat_date' => daysAgo(30), 'page_views' => 999, 'unique_visitors' => 99],  // far outside
    ]);
    DB::table('traffic_sources_daily')->insert([
        ['stat_date' => daysAgo(1), 'source_code' => 'google', 'visits' => 30],
        ['stat_date' => daysAgo(3), 'source_code' => 'whatsapp', 'visits' => 10],
    ]);
    DB::table('device_stats_daily')->insert([['stat_date' => daysAgo(1), 'device_type' => 'mobile', 'visits' => 36], ['stat_date' => daysAgo(1), 'device_type' => 'desktop', 'visits' => 4]]);
    DB::table('event_stats_daily')->insert([['stat_date' => daysAgo(2), 'event_code' => 'share_click', 'count' => 7]]);
    DB::table('post_views_daily')->insert([
        ['post_id' => $a, 'stat_date' => daysAgo(1), 'views' => 50],
        ['post_id' => $b, 'stat_date' => daysAgo(3), 'views' => 20],
        ['post_id' => $story, 'stat_date' => daysAgo(2), 'views' => 5],
        ['post_id' => $b, 'stat_date' => daysAgo(30), 'views' => 900],
    ]);

    return compact('a', 'b', 'story', 'economy', 'banks');
}

test('the report of a period: days, totals, the change from before, sources, devices and interactions', function () {
    storedWeek();
    $d = StatsReport::for('7')->data;

    expect(count($d['days']))->toBe(7)->and($d['from'])->toBe(daysAgo(6))->and($d['to'])->toBe(daysAgo(0))
        ->and(collect($d['days'])->firstWhere('date', daysAgo(1)))->toBe(['date' => daysAgo(1), 'page_views' => 120, 'visitors' => 40])
        ->and($d['totals'])->toMatchArray(['page_views' => 200, 'visitors' => 70, 'visits' => 40, 'pages_per_visit' => '5.0', 'readings' => 75,
            'previous_page_views' => 50])
        ->and(StatsReport::change(200, 50))->toBe('+300.0%')->and(StatsReport::change(25, 50))->toBe('−50.0%')->and(StatsReport::change(5, 0))->toBeNull()
        ->and($d['sources'])->toBe([['code' => 'google', 'name' => 'جوجل', 'count' => 30, 'share' => '75.0'], ['code' => 'whatsapp', 'name' => 'واتساب', 'count' => 10, 'share' => '25.0']])
        ->and(array_column($d['devices'], 'count', 'code'))->toBe(['mobile' => 36, 'desktop' => 4])
        ->and(array_column($d['events'], 'count', 'code'))->toBe(['share_click' => 7]);
});

test('readings per post, category (a sub-category under its main one), special section and author (22.4)', function () {
    $w = storedWeek();
    $d = StatsReport::for('7')->data;

    expect(array_column($d['posts'], 'count', 'id'))->toBe([$w['a'] => 50, $w['b'] => 20, $w['story'] => 5])
        ->and(collect($d['posts'])->firstWhere('id', $w['story'])['live'])->toBeFalse()
        ->and($d['by_category'])->toBe([['name' => 'اقتصاد ← بنوك', 'count' => 50, 'posts' => 1], ['name' => 'اقتصاد', 'count' => 20, 'posts' => 1]])
        ->and($d['by_section'])->toBe([['name' => 'قصص نجاح', 'count' => 5, 'posts' => 1]])
        ->and($d['by_author'])->toBe([['name' => 'سلمى', 'count' => 50, 'posts' => 1], ['name' => 'فريق رَقَم', 'count' => 25, 'posts' => 2]]);
});

test('today is live: the larger of the stored row and Redis, as the transfer will write it', function () {
    $post = insertPost(publishedPostValues(['published_at' => now()->subDay()]));
    DB::table('site_stats_daily')->insert(['stat_date' => daysAgo(0), 'page_views' => 1, 'unique_visitors' => 1]);
    DB::table('post_views_daily')->insert(['post_id' => $post, 'stat_date' => daysAgo(0), 'views' => 1]);
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post, 'referrer' => 'https://www.google.com/']);
    siteHit(['type' => 'view', 'path' => '/a'], ip: '198.51.100.9');
    siteHit(['type' => 'view', 'path' => '/b'], ip: '198.51.100.9');

    $d = StatsReport::for('1')->data;

    expect($d['live'])->toBeTrue()->and($d['days'])->toBe([['date' => daysAgo(0), 'page_views' => 3, 'visitors' => 2]])
        ->and(array_column($d['posts'], 'count', 'id'))->toBe([$post => 1])
        ->and(array_column($d['sources'], 'count', 'code'))->toBe(['google' => 1, 'direct' => 1]);
});

test('today\'s visitors never exceed today\'s page views, and a counter Redis invented is left out', function () {
    $day = StatsKeys::today();
    StatsKeys::redis()->hset(StatsKeys::day($day, 'site'), 'page_views', 2);
    StatsKeys::redis()->sadd(StatsKeys::day($day, 'visitors'), 'a', 'b', 'c', 'd');
    StatsKeys::redis()->hset(StatsKeys::day($day, 'sources'), 'myspace', 5);
    StatsKeys::redis()->hset(StatsKeys::day($day, 'sources'), 'google', 1);

    $d = StatsReport::for('1')->data;

    expect($d['days'][0])->toMatchArray(['page_views' => 2, 'visitors' => 2])
        ->and($d['sources'])->toBe([['code' => 'google', 'name' => 'جوجل', 'count' => 1, 'share' => '16.7']]);
});

test('the previous period is the same number of days just before', function () {
    DB::table('site_stats_daily')->insert([
        ['stat_date' => daysAgo(7), 'page_views' => 10, 'unique_visitors' => 1],
        ['stat_date' => daysAgo(13), 'page_views' => 20, 'unique_visitors' => 1],
        ['stat_date' => daysAgo(14), 'page_views' => 1000, 'unique_visitors' => 1],
        ['stat_date' => daysAgo(6), 'page_views' => 3, 'unique_visitors' => 1],
    ]);

    expect(StatsReport::for('7')->data['totals'])->toMatchArray(['page_views' => 3, 'previous_page_views' => 30]);
});

test('the other modules over the same days, in one place (22.9)', function () {
    $c = adCampaign();
    DB::table('ad_stats_daily')->insert(['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => daysAgo(1), 'impressions' => 400, 'clicks' => 10]);
    $term = DB::table('search_terms')->insertGetId(['normalized_term' => 'دولار']);
    DB::table('search_stats_daily')->insert(['term_id' => $term, 'stat_date' => daysAgo(2), 'searches' => 12, 'zero_result_searches' => 3]);
    DB::table('subscribers')->insert([
        ['email' => 'new@example.com', 'source_placement' => 'footer', 'status' => 'active', 'confirmed_at' => now()->subDay(), 'unsubscribed_at' => null],
        ['email' => 'gone@example.com', 'source_placement' => 'footer', 'status' => 'unsubscribed', 'confirmed_at' => now()->subDays(40), 'unsubscribed_at' => now()->subDays(2)],
    ]);
    $conv = DB::table('bot_conversations')->insertGetId(['session_hash' => str_repeat('b', 64), 'started_at' => now()->subDay()]);
    DB::table('bot_messages')->insert([
        ['conversation_id' => $conv, 'role' => 'user', 'content' => 'سؤال', 'match_score' => 0.9, 'matched_entry_id' => null, 'created_at' => now()->subDay()],
    ]);
    DB::table('contact_messages')->insert([
        ['name' => 'س', 'email' => 's@example.com', 'subject' => 'م', 'message' => 'نص', 'created_at' => now()->subDays(3)],
        ['name' => 'ص', 'email' => 'p@example.com', 'subject' => 'م', 'message' => 'نص', 'created_at' => now()],              // today counts
        ['name' => 'ع', 'email' => 'q@example.com', 'subject' => 'م', 'message' => 'نص', 'created_at' => now()->subDays(8)],  // before the period
    ]);

    $m = StatsReport::for('7')->data['modules'];

    expect($m['search'])->toBe(['searches' => 12, 'zero' => 3, 'top' => ['دولار']])
        ->and($m['ads'])->toBe(['views' => 400, 'clicks' => 10, 'campaigns' => 1, 'ctr' => '2.50'])
        ->and($m['newsletter'])->toBe(['joined' => 1, 'left' => 1, 'active' => 1])
        ->and($m['chatbot'])->toBe(['conversations' => 1, 'questions' => 1, 'answered' => 0, 'rate' => '0.0'])
        ->and($m['contact'])->toBe(['messages' => 2]);
});

test('a period picked backwards is turned round and one longer than a year is cut, saying so', function () {
    expect(StatsReport::for('custom', daysAgo(1), daysAgo(5))->data)->toMatchArray(['from' => daysAgo(5), 'to' => daysAgo(1), 'problem' => 'بداية الفترة بعد نهايتها، فبُدّلتا.'])
        ->and(StatsReport::for('custom', daysAgo(800), daysAgo(0))->data)->toMatchArray(['from' => daysAgo(365), 'problem' => 'الفترة أطول من سنة، فعُرضت آخر سنة منها.'])
        ->and(StatsReport::for('30')->data['from'])->toBe(daysAgo(29))
        ->and(StatsReport::for('nonsense')->data['from'])->toBe(daysAgo(6));
});

test('the screen shows the numbers and the chart, switches period without reloading the page, and never polls', function () {
    $w = storedWeek();

    Livewire::test(Statistics::class)->assertOk()
        ->assertSee('مشاهدات الصفحات')->assertSeeHtml('data-tile="مشاهدات الصفحات">200<')->assertSee('+300.0% عن الفترة السابقة')
        ->assertSeeHtml('data-day="'.daysAgo(1).'"')->assertSee('خبر البنوك')->assertSee('اقتصاد ← بنوك')->assertSee('سلمى')
        ->assertSee('جوجل')->assertSee('مشاركة على السوشيال')->assertDontSeeHtml('wire:poll')
        ->set('data.period', '30')->assertSeeHtml('data-tile="مشاهدات الصفحات">250<')
        ->set('data.period', '1')->assertSeeHtml('data-tile="مشاهدات الصفحات">0<');

    expect(file_get_contents(resource_path('views/filament/statistics.blade.php')))->not->toContain('wire:poll')->not->toContain('setInterval')
        ->and($w['a'])->toBeInt();
});

test('texts that came from outside are escaped on the screen', function () {
    $post = insertPost(publishedPostValues(['title' => '<img src=x onerror=alert(1)> خبر', 'published_at' => now()->subDays(3)]));
    DB::table('post_views_daily')->insert(['post_id' => $post, 'stat_date' => daysAgo(1), 'views' => 3]);
    DB::table('not_found_paths')->insert(['path' => '/"><script>alert(1)</script>', 'hits' => 4]);

    Livewire::test(Statistics::class)->assertDontSeeHtml('<img src=x')->assertSeeHtml('&lt;img src=x')
        ->assertDontSeeHtml('<script>alert(1)')->assertSeeHtml('&lt;script&gt;alert(1)');
});

test('the export is an Excel file of the period, sheet by sheet, and it is written to the audit log', function () {
    $w = storedWeek();
    DB::table('posts')->where('id', $w['a'])->update(['title' => '=HYPERLINK("https://evil.example")']);

    $response = Livewire::test(Statistics::class)->callAction('export')->assertFileDownloaded('statistics-'.daysAgo(6).'-'.daysAgo(0).'.xlsx');
    $bytes = base64_decode((string) data_get($response->effects, 'download.content'));
    $file = tempnam(sys_get_temp_dir(), 'sts');
    file_put_contents($file, $bytes);
    $reader = new Reader;
    $reader->open($file);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();
    @unlink($file);

    expect(array_keys($sheets))->toBe(['الملخص', 'الأيام', 'المصادر', 'الأجهزة', 'التفاعلات', 'الأكثر قراءة', 'حسب التصنيف', 'حسب القسم', 'حسب الكاتب'])
        ->and($sheets['الملخص'][2])->toBe(['مشاهدات الصفحات', 200])
        ->and(count($sheets['الأيام']))->toBe(8)
        ->and($sheets['الأكثر قراءة'][1])->toBe(['=HYPERLINK("https://evil.example")', 'خبر', 'بنوك', 50])
        ->and(DB::table('audit_log')->where('action', 'export')->where('actor_user_id', $this->admin->id)->count())->toBe(1)
        ->and(DB::table('audit_log_changes')->where('field', 'statistics.period')->value('new_value'))->toBe(daysAgo(6).' — '.daysAgo(0));
});

test('the export keeps the formula as text, never as a formula', function () {
    $w = storedWeek();
    DB::table('posts')->where('id', $w['a'])->update(['title' => '=1+1']);
    $response = Livewire::test(Statistics::class)->callAction('export');
    $zip = new ZipArchive;
    $file = tempnam(sys_get_temp_dir(), 'stz');
    file_put_contents($file, base64_decode((string) data_get($response->effects, 'download.content')));
    $zip->open($file);
    $xml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $xml .= (string) $zip->getFromIndex($i);
    }
    $zip->close();
    @unlink($file);

    expect($xml)->toContain('=1+1')->not->toContain('<f>');
});

test('the home shows today\'s visits and the most read today', function () {
    $post = insertPost(publishedPostValues(['title' => 'الأكثر قراءة اليوم هنا', 'published_at' => now()->subDay()]));
    siteHit(['type' => 'view', 'path' => "/news/{$post}/x", 'post_id' => $post]);

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('زيارات اليوم')->assertSee('مشاهدات الصفحات: 1')
        ->assertSee('الأكثر قراءة اليوم هنا — 1')->assertDontSee('تظهر مع مرحلة الإحصائيات');
});

test('the screens are for signed-in staff only', function () {
    $urls = [Statistics::getUrl(), NotFoundPathResource::getUrl()];
    auth()->logout();

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect();
    }
});

// ---- missing pages (22.10) ---------------------------------------------------------------------------

test('the missing pages screen lists the open ones, most reached first, and cannot add, edit or delete', function () {
    DB::table('not_found_paths')->insert([
        ['path' => '/less-reached', 'hits' => 3, 'resolved_at' => null], ['path' => '/most-reached', 'hits' => 9, 'resolved_at' => null], ['path' => '/done', 'hits' => 50, 'resolved_at' => now()],
    ]);

    Livewire::test(ListNotFoundPaths::class)->assertOk()
        ->assertCanSeeTableRecords(NotFoundPath::query()->whereIn('path', ['/most-reached', '/less-reached'])->get())->assertSeeInOrder(['/most-reached', '/less-reached'])
        ->assertCanNotSeeTableRecords(NotFoundPath::query()->where('path', '/done')->get())
        ->filterTable('state', 'resolved')->assertCanSeeTableRecords(NotFoundPath::query()->where('path', '/done')->get());

    expect(NotFoundPathResource::canCreate())->toBeFalse()->and(NotFoundPathResource::canDeleteAny())->toBeFalse()
        ->and(NotFoundPathResource::canEdit(new NotFoundPath))->toBeFalse()->and(NotFoundPathResource::getPages())->toHaveKeys(['index'])->toHaveCount(1);
});

test('"make a redirect" opens the redirects screen with the old address filled in', function () {
    DB::table('not_found_paths')->insert(['path' => '/old/خبر-قديم', 'hits' => 3]);
    $record = NotFoundPath::query()->find('/old/خبر-قديم');

    Livewire::test(ListNotFoundPaths::class)
        ->assertTableActionHasUrl('redirect', RedirectResource::getUrl('index', ['tableAction' => 'create', 'tableActionArguments' => ['from' => '/old/خبر-قديم']]), $record);

    Livewire::withQueryParams(['tableAction' => 'create', 'tableActionArguments' => ['from' => '/old/خبر-قديم']])
        ->test(ManageRedirects::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'create')
        ->assertActionDataSet(['from_path' => '/old/خبر-قديم', 'status_code' => 301]);
});

test('a crafted address in the link is not filled in', function () {
    Livewire::withQueryParams(['tableAction' => 'create', 'tableActionArguments' => ['from' => 'https://evil.example/']])
        ->test(ManageRedirects::class)->call('mountDefaultActions')->assertSet('mountedActions.0.name', 'create')->assertActionDataSet(['from_path' => null]);
});

test('a redirect from a missing address marks it handled, whether the admin or the application adds it', function () {
    DB::table('not_found_paths')->insert([['path' => '/by-admin', 'hits' => 3], ['path' => '/news/5/old', 'hits' => 2], ['path' => '/other', 'hits' => 1]]);

    Redirect::query()->create(['from_path' => '/by-admin', 'to_path' => '/new', 'status_code' => 301]);
    (fn () => $this->put('/news/5/old', '/news/5/new', 301, null))->call(app(RedirectManager::class));

    expect(DB::table('not_found_paths')->whereNotNull('resolved_at')->orderBy('path')->pluck('path')->all())->toBe(['/by-admin', '/news/5/old']);
});

test('marking handled, reopening and handling several at once', function () {
    DB::table('not_found_paths')->insert([['path' => '/a', 'hits' => 3], ['path' => '/b', 'hits' => 2], ['path' => '/c', 'hits' => 1]]);
    $find = fn (string $p) => NotFoundPath::query()->find($p);

    Livewire::test(ListNotFoundPaths::class)->callTableAction('resolve', $find('/a'));
    expect($find('/a')->resolved_at)->not->toBeNull();
    Livewire::test(ListNotFoundPaths::class)->filterTable('state', 'resolved')->callTableAction('reopen', $find('/a'));
    expect($find('/a')->resolved_at)->toBeNull();
    Livewire::test(ListNotFoundPaths::class)->callTableBulkAction('resolveMany', NotFoundPath::query()->whereIn('path', ['/b', '/c'])->get());
    expect(DB::table('not_found_paths')->whereNull('resolved_at')->pluck('path')->all())->toBe(['/a']);
});

test('a handled page reached again says so', function () {
    DB::table('not_found_paths')->insert(['path' => '/back', 'hits' => 3, 'first_seen_at' => now()->subDays(5), 'resolved_at' => now()->subDay(), 'last_seen_at' => now()]);

    Livewire::test(ListNotFoundPaths::class)->filterTable('state', 'resolved')->assertSee('عُولجت وما زالت تُزار');
});
