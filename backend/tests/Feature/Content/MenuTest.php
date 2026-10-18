<?php

use App\Filament\Pages\MenuEditor;
use App\Models\Category;
use App\Models\User;
use App\Navigation\MenuStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->store = app(MenuStore::class);
    $this->energy = Category::where('slug', 'طاقة')->value('id');
    $this->podcast = DB::table('sections')->where('key', 'podcast')->value('id');
    $this->page = DB::table('pages')->insertGetId(['title' => 'من نحن', 'slug' => 'about', 'system_key' => 'about']);
});

function menuLink(string $label, string $type, array $target = [], array $extra = []): array
{
    return ['label_ar' => $label, 'type' => $type, 'children' => [], ...$target, ...$extra];
}

function labels(string $menu): array
{
    return collect(app(MenuStore::class)->load($menu))->map(fn ($i) => [$i['label_ar'], collect($i['children'])->pluck('label_ar')->all()])->all();
}

test('the three menus exist', function () {
    expect($this->store->menus())->toBe(['header' => 'القائمة الرئيسية', 'footer_main' => 'روابط الفوتر', 'footer_legal' => 'الروابط القانونية']);
});

test('links to a page, a category, a section and an address are saved in the order given', function () {
    $this->store->save('header', [
        menuLink('الطاقة', 'category', ['category_id' => $this->energy]),
        menuLink('البودكاست', 'section', ['section_id' => $this->podcast]),
        menuLink('من نحن', 'page', ['page_id' => $this->page]),
        menuLink('فيسبوك', 'url', ['url' => 'https://facebook.com/raqam'], ['opens_new_tab' => true]),
    ]);

    $items = $this->store->load('header');
    expect(array_column($items, 'label_ar'))->toBe(['الطاقة', 'البودكاست', 'من نحن', 'فيسبوك'])
        ->and(array_column($items, 'type'))->toBe(['category', 'section', 'page', 'url'])
        ->and($items[3]['opens_new_tab'])->toBeTrue()->and($items[0]['category_id'])->toBe($this->energy);
});

test('a link can have children, one level deep, in their own order', function () {
    $this->store->save('header', [
        menuLink('الأخبار', 'url', ['url' => '/news'], ['children' => [menuLink('محلي', 'category', ['category_id' => $this->energy]), menuLink('عالمي', 'url', ['url' => '/world'])]]),
        menuLink('اتصل بنا', 'url', ['url' => '/contact']),
    ]);

    expect(labels('header'))->toBe([['الأخبار', ['محلي', 'عالمي']], ['اتصل بنا', []]]);
});

test('children of a child are dropped: only one level exists', function () {
    $this->store->save('header', [
        menuLink('أ', 'url', ['url' => '/a'], ['children' => [menuLink('ب', 'url', ['url' => '/b'], ['children' => [menuLink('ج', 'url', ['url' => '/c'])]])]]),
    ]);

    expect(DB::table('menu_items')->count())->toBe(2)->and(labels('header'))->toBe([['أ', ['ب']]]);
});

test('saving again with the order changed reorders and keeps the same rows', function () {
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a']), menuLink('ب', 'url', ['url' => '/b'])]);
    [$a, $b] = $this->store->load('header');

    $this->store->save('header', [$b, $a]);

    $after = $this->store->load('header');
    expect(array_column($after, 'label_ar'))->toBe(['ب', 'أ'])->and(array_column($after, 'id'))->toBe([$b['id'], $a['id']]);
});

test('a link left out is deleted, with its children', function () {
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a'], ['children' => [menuLink('أ1', 'url', ['url' => '/a1'])]]), menuLink('ب', 'url', ['url' => '/b'])]);
    [$a, $b] = $this->store->load('header');

    $this->store->save('header', [$b]);

    expect(labels('header'))->toBe([['ب', []]])->and(DB::table('menu_items')->count())->toBe(1);
});

test('a change of the target of a link replaces the old target', function () {
    $this->store->save('header', [menuLink('الطاقة', 'category', ['category_id' => $this->energy])]);
    $item = $this->store->load('header')[0];

    $this->store->save('header', [array_merge($item, ['type' => 'url', 'url' => '/energy'])]);

    $row = DB::table('menu_items')->first();
    expect($row->url)->toBe('/energy')->and($row->category_id)->toBeNull();
});

test('the leftover values of the other target types are ignored', function () {
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a', 'category_id' => $this->energy, 'page_id' => $this->page])]);

    $row = DB::table('menu_items')->first();
    expect($row->url)->toBe('/a')->and($row->category_id)->toBeNull()->and($row->page_id)->toBeNull();
});

test('a link needs a name, a type and a target of that type', function (array $item) {
    expect(fn () => $this->store->save('header', [$item]))->toThrow(ValidationException::class);
    expect(DB::table('menu_items')->count())->toBe(0);
})->with([
    'no name' => [['label_ar' => '', 'type' => 'url', 'url' => '/a']],
    'name too long' => [['label_ar' => str_repeat('ا', 61), 'type' => 'url', 'url' => '/a']],
    'no type' => [['label_ar' => 'أ']],
    'unknown type' => [['label_ar' => 'أ', 'type' => 'script', 'url' => '/a']],
    'page without page' => [['label_ar' => 'أ', 'type' => 'page']],
    'category that does not exist' => [['label_ar' => 'أ', 'type' => 'category', 'category_id' => 99999]],
    'section that does not exist' => [['label_ar' => 'أ', 'type' => 'section', 'section_id' => 99999]],
    'page that does not exist' => [['label_ar' => 'أ', 'type' => 'page', 'page_id' => 99999]],
    'url without url' => [['label_ar' => 'أ', 'type' => 'url']],
]);

test('addresses must stay on the site or be a web address, never script or protocol-relative', function (string $url, bool $ok) {
    $attempt = fn () => $this->store->save('header', [menuLink('أ', 'url', ['url' => $url])]);

    $ok ? expect($attempt)->not->toThrow(ValidationException::class) : expect($attempt)->toThrow(ValidationException::class);
})->with([
    ['/about', true], ['/', true], ['/news?x=1#top', true], ['https://example.com/p', true], ['http://example.com', true],
    ['//evil.example', false], ['/\\evil.example', false], ['javascript:alert(1)', false], ['JaVaScRiPt:alert(1)', false],
    ['data:text/html,x', false], ['ftp://x.com', false], ['about', false], ['https://', false], ['/a b', false],
]);

test('a wrong link in the middle stops the whole save: nothing is half saved', function () {
    $this->store->save('header', [menuLink('قديم', 'url', ['url' => '/old'])]);

    expect(fn () => $this->store->save('header', [menuLink('جديد', 'url', ['url' => '/new']), menuLink('خطأ', 'url', ['url' => 'javascript:x'])]))->toThrow(ValidationException::class);

    expect(labels('header'))->toBe([['قديم', []]]);
});

test('the error says which link is wrong', function () {
    try {
        $this->store->save('header', [menuLink('جيد', 'url', ['url' => '/ok']), menuLink('سيء', 'url', ['url' => 'x'], ['children' => [menuLink('ابن', 'url', ['url' => '/c']), menuLink('', 'url', ['url' => '/d'])]])]);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('1.url', '1.children.1.label_ar');
    }
});

test('an id that belongs to another menu is not updated, a new link is made instead', function () {
    $this->store->save('footer_main', [menuLink('تذييل', 'url', ['url' => '/footer'])]);
    $foreign = $this->store->load('footer_main')[0];

    $this->store->save('header', [array_merge($foreign, ['label_ar' => 'محاولة'])]);

    expect(labels('footer_main'))->toBe([['تذييل', []]])->and(labels('header'))->toBe([['محاولة', []]]);
});

test('menus are independent of each other', function () {
    $this->store->save('header', [menuLink('رأس', 'url', ['url' => '/h'])]);
    $this->store->save('footer_legal', [menuLink('خصوصية', 'url', ['url' => '/privacy'])]);
    $this->store->save('header', []);

    expect(labels('header'))->toBe([])->and(labels('footer_legal'))->toBe([['خصوصية', []]]);
});

test('an unknown menu is refused', function () {
    expect(fn () => $this->store->save('sidebar', []))->toThrow(InvalidArgumentException::class);
});

test('a menu has a size limit', function () {
    $items = array_map(fn ($i) => menuLink("ر{$i}", 'url', ['url' => "/{$i}"]), range(1, MenuStore::MAX_ITEMS + 1));

    expect(fn () => $this->store->save('header', $items))->toThrow(ValidationException::class);
});

test('a change is audited with the menu before and after, an unchanged save is not', function () {
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a'])], $this->admin->id);
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a'])], $this->admin->id);
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a']), menuLink('ب', 'url', ['url' => '/b'])], $this->admin->id);

    $rows = DB::table('audit_log')->where('subject_table', 'menus')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->actor_user_id)->toBe($this->admin->id);
    $change = DB::table('audit_log_changes')->where('audit_id', $rows[1]->id)->sole();
    expect($change->old_value)->toContain('أ')->not->toContain('ب')->and($change->new_value)->toContain('ب');
});

test('hiding a link keeps it but marks it', function () {
    $this->store->save('header', [menuLink('أ', 'url', ['url' => '/a'], ['is_active' => false])]);

    expect($this->store->load('header')[0]['is_active'])->toBeFalse();
});

test('deleting a category or a section removes its links from the menu, deleting a page too', function () {
    $category = Category::create(['name_ar' => 'مؤقت', 'slug' => 'tmp']);
    $this->store->save('header', [menuLink('م', 'category', ['category_id' => $category->id]), menuLink('ص', 'page', ['page_id' => $this->page]), menuLink('ثابت', 'url', ['url' => '/x'])]);

    $category->delete();
    DB::table('pages')->where('id', $this->page)->delete();

    expect(labels('header'))->toBe([['ثابت', []]]);
});

// ---- the screen ----------------------------------------------------------------------------------

test('the screen shows the saved links in tabs and saves what is arranged', function () {
    $this->store->save('header', [menuLink('الطاقة', 'category', ['category_id' => $this->energy])]);

    $component = Livewire::test(MenuEditor::class)->assertSee('القائمة الرئيسية')->assertSee('الطاقة');
    $state = $component->get('data');
    $state['header'][] = ['label_ar' => 'اتصل بنا', 'type' => 'url', 'url' => '/contact', 'opens_new_tab' => false, 'is_active' => true, 'children' => []];

    $component->fillForm($state)->call('save')->assertNotified();

    expect(array_column($this->store->load('header'), 'label_ar'))->toBe(['الطاقة', 'اتصل بنا']);
});

test('the screen reports a wrong link instead of saving', function () {
    $component = Livewire::test(MenuEditor::class);
    $state = $component->get('data');
    $state['header'] = [['label_ar' => 'خطر', 'type' => 'url', 'url' => 'javascript:alert(1)', 'opens_new_tab' => false, 'is_active' => true, 'children' => []]];

    $component->fillForm($state)->call('save')->assertNotified();

    expect(DB::table('menu_items')->count())->toBe(0);
});
