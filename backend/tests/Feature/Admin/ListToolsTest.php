<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/* 21.17: search in every list, filters wherever a list has a state, and bulk actions on the lists that grow. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

/** Every resource with the table of its list page. @return array<string, Table> */
function panelTables(): array
{
    $tables = [];
    foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
        $resource = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
        $page = $resource::getPages()['index']->getPage();
        $tables[class_basename($resource)] = Livewire::test($page)->instance()->getTable();
    }

    return $tables;
}

test('every list can be searched', function () {
    foreach (panelTables() as $resource => $table) {
        expect(collect($table->getColumns())->contains(fn ($c) => $c->isSearchable()))->toBeTrue("{$resource} has no search");
    }
});

test('every list whose records have a state (shown, published, on, status) can be filtered by it', function () {
    foreach (panelTables() as $resource => $table) {
        $model = $table->getModel();
        $columns = Schema::getColumnListing((new $model)->getTable());
        if (array_intersect($columns, ['is_active', 'is_visible', 'is_published', 'is_enabled', 'status', 'status_code', 'deleted_at']) !== []) {
            expect($table->getFilters())->not->toBeEmpty("{$resource} has a state but no filter");
        }
    }
});

test('every list where records are deleted one by one also deletes them in bulk, unless named here with the reason', function () {
    $oneByOne = [
        'AdCampaignResource' => 'a campaign with views or clicks is never deleted (paused instead): decided one at a time',
        'AdvertiserResource' => 'deletable only with no campaigns, shown per row',
        'ServiceResource' => 'prices and visibility go through the service editor, which refreshes the site',
        'CurrencyResource' => 'a short fixed list; removal keeps the history',
        'CityResource' => 'twelve cities; removal answers 410 for the city page, decided one at a time',
        'EraResource' => 'a short list; deletion refused while history items use the era',
        'SectorResource' => 'a short list; deletion refused while companies use the sector',
        'SynonymGroupResource' => 'each group is checked against the others when saved',
    ];
    foreach (panelTables() as $resource => $table) {
        $deletes = collect($table->getFlatRecordActions())->contains(fn ($a) => in_array($a->getName(), ['delete', 'remove', 'trash'], true));
        if ($deletes && ! isset($oneByOne[$resource])) {
            expect($table->getFlatBulkActions())->not->toBeEmpty("{$resource} deletes one by one but has no bulk action");
        }
    }
});

test('bulk delete removes what may go, keeps what is still used, and audits each one', function () {
    $free = Tag::create(['name_ar' => 'حر', 'slug' => 'free']);
    $other = Tag::create(['name_ar' => 'آخر', 'slug' => 'other']);
    $parent = Category::create(['name_ar' => 'أب', 'slug' => 'parent-cat', 'is_active' => true]);
    Category::create(['name_ar' => 'ابن', 'slug' => 'child-cat', 'parent_id' => $parent->id, 'is_active' => true]);
    $lonely = Category::create(['name_ar' => 'وحيد', 'slug' => 'lonely-cat', 'is_active' => true]);

    Livewire::test(ListTags::class)->callTableBulkAction('deleteSelected', [$free, $other]);
    Livewire::test(ListCategories::class)->callTableBulkAction('deleteSelected', [$parent, $lonely])->assertNotified('حُذف: 1');

    expect(Tag::count())->toBe(0)->and(Category::whereKey($parent->id)->exists())->toBeTrue()->and(Category::whereKey($lonely->id)->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('subject_table', 'tags')->where('action', 'delete')->count())->toBe(2)
        ->and(DB::table('redirects')->where('from_path', '/tag/free')->value('status_code'))->toBe(410);   // through the model: the address answers 410
});

test('bulk publish and unpublish go through the model; a system page is never deleted in bulk', function () {
    $about = Page::create(['title' => 'من نحن', 'slug' => 'about', 'is_published' => false]);
    DB::table('pages')->where('id', $about->id)->update(['system_key' => 'about']);
    $custom = Page::create(['title' => 'حملة', 'slug' => 'campaign', 'is_published' => false]);

    Livewire::test(ListPages::class)->callTableBulkAction('is_publishedOn', [$about, $custom]);
    expect(Page::where('is_published', true)->count())->toBe(2)
        ->and(DB::table('audit_log')->where('subject_table', 'pages')->where('action', 'update')->count())->toBe(2);

    Livewire::test(ListPages::class)->callTableBulkAction('deleteSelected', [$about->fresh(), $custom->fresh()]);
    expect(Page::pluck('slug')->all())->toBe(['about']);
});

test('bulk hide of categories hides each one', function () {
    $a = Category::create(['name_ar' => 'أ', 'slug' => 'cat-a', 'is_active' => true]);
    $b = Category::create(['name_ar' => 'ب', 'slug' => 'cat-b', 'is_active' => true]);

    Livewire::test(ListCategories::class)->callTableBulkAction('is_activeOff', [$a, $b]);

    expect(Category::whereIn('id', [$a->id, $b->id])->where('is_active', false)->count())->toBe(2);
});

test('bulk trash of pictures moves the free ones and keeps a picture still in use', function () {
    $free = insertMedia();
    $used = insertMedia();
    insertPost(['cover_media_id' => $used]);

    Livewire::test(ListMediaAssets::class)
        ->callTableBulkAction('trashSelected', MediaAsset::whereIn('id', [$free, $used])->get())->assertNotified('نُقل إلى السلة: 1');

    expect(DB::table('media_assets')->where('id', $free)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('media_assets')->where('id', $used)->value('deleted_at'))->toBeNull();
});
