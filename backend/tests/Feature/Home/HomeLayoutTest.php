<?php

use App\Filament\Pages\HomeLayoutEditor;
use App\Jobs\SendSiteWebhook;
use App\Models\User;
use App\Navigation\HomeLayout;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    ensurePostKinds();
    $this->layout = app(HomeLayout::class);
});

/** @return list<string> "type:target" of the stored blocks, in order */
function storedOrder(): array
{
    return DB::table('home_blocks')->orderBy('sort_order')->get()
        ->map(fn ($r) => $r->block_type.(isset(HomeLayout::TARGET[$r->block_type]) ? ':'.$r->{HomeLayout::TARGET[$r->block_type]} : '').($r->is_active ? '' : '(hidden)'))->all();
}

function layoutRefusal(Closure $save): array
{
    try {
        $save();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->all();
    }

    return [];
}

// ---- the order ---------------------------------------------------------------------------------------

test('the suggested order: fixed blocks, each main category, then every special section (awareness as its own block)', function () {
    $economy = insertCategory('اقتصاد');
    insertCategory('بنوك', $economy);
    $local = insertCategory('محلي');

    $types = array_map(fn ($b) => $b['block_type'].($b['category_id'] ? ':'.$b['category_id'] : ''), $this->layout->defaults());

    expect(array_slice($types, 0, 5))->toBe(['hero', 'breaking', 'markets', 'latest', 'ad'])
        ->and($types)->toContain("category:{$economy}", "category:{$local}", 'most_read', 'videos', 'awareness', 'newsletter')
        ->and(count(array_filter($types, fn ($t) => $t === 'section')))->toBe(6)
        ->and(count(array_filter($types, fn ($t) => str_starts_with($t, 'category:'))))->toBe(DB::table('categories')->whereNull('parent_id')->where('is_active', true)->count())
        ->and($types)->not->toContain('category:'.DB::table('categories')->where('slug', 'بنوك')->value('id'));
});

test('home:install writes the suggested order once and never touches an order that exists', function () {
    $this->artisan('home:install')->assertSuccessful()->expectsOutputToContain('Added');
    $first = storedOrder();
    $this->layout->save([homeBlock('latest'), homeBlock('hero')], null);
    $this->artisan('home:install')->assertSuccessful()->expectsOutputToContain('Added 0');

    expect($first)->not->toBeEmpty()->and(storedOrder())->toBe(['latest', 'hero']);
});

test('saving keeps the order, ids and visibility; blocks can swap places; a block left out is removed', function () {
    $c = insertCategory('اقتصاد');
    $this->layout->save([homeBlock('hero'), homeBlock('category', ['category_id' => $c]), homeBlock('newsletter')], null);
    $ids = DB::table('home_blocks')->orderBy('sort_order')->pluck('id')->all();

    $loaded = $this->layout->load();
    $loaded[0]['is_active'] = false;
    $this->layout->save([$loaded[1], $loaded[0]], null);

    expect(storedOrder())->toBe(["category:{$c}", 'hero(hidden)'])
        ->and(DB::table('home_blocks')->orderBy('sort_order')->pluck('id')->all())->toBe([$ids[1], $ids[0]]);
});

test('the blocks can be reordered without breaking the unique position', function () {
    $this->layout->save([homeBlock('hero'), homeBlock('breaking'), homeBlock('latest')], null);
    $loaded = $this->layout->load();

    $this->layout->save(array_reverse($loaded), null);
    expect(storedOrder())->toBe(['latest', 'breaking', 'hero']);

    $loaded = $this->layout->load();
    $loaded[1]['is_active'] = false;
    $this->layout->save([$loaded[0], $loaded[1]], null);
    expect(storedOrder())->toBe(['latest', 'breaking(hidden)']);
});

test('a block needs its target, an existing one, and an ad slot that is a place on the page', function () {
    expect(layoutRefusal(fn () => $this->layout->save([homeBlock('category')], null)))->toContain('اختر التصنيف.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('section')], null)))->toContain('اختر القسم.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('playlist')], null)))->toContain('اختر قائمة التشغيل.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('ad')], null)))->toContain('اختر مكان الإعلان.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('category', ['category_id' => 999999])], null)))->toContain('الوجهة المختارة غير موجودة.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('ad', ['placement_code' => 'popup'])], null)))->toContain('مكان الإعلان هذا لا يوضع في الصفحة.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('carousel')], null)))->toContain('نوع البلوك غير معروف.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('latest', ['item_limit' => 0])], null)))->toContain('عدد العناصر من 1 إلى 24.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('latest', ['item_limit' => 25])], null)))->toContain('عدد العناصر من 1 إلى 24.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('latest', ['title_ar' => str_repeat('ع', 81)])], null)))->toContain('العنوان أطول من 80 حرفاً.')
        ->and(DB::table('home_blocks')->count())->toBe(0);
});

test('the same category twice, or a one-off block twice, is refused; other targets are fine', function () {
    $a = insertCategory('أ');
    $b = insertCategory('ب');

    expect(layoutRefusal(fn () => $this->layout->save([homeBlock('category', ['category_id' => $a]), homeBlock('category', ['category_id' => $a])], null)))->toContain('هذا البلوك موجود مرة أخرى في الصفحة.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('hero'), homeBlock('hero')], null)))->toContain('هذا البلوك موجود مرة أخرى في الصفحة.')
        ->and(layoutRefusal(fn () => $this->layout->save([homeBlock('category', ['category_id' => $a]), homeBlock('category', ['category_id' => $b]),
            homeBlock('ad', ['placement_code' => 'between_sections']), homeBlock('ad', ['placement_code' => 'sidebar_mpu'])], null)))->toBe([]);
});

test('only the chosen target is kept, whatever else came with the block', function () {
    $c = insertCategory('أ');
    $this->layout->save([homeBlock('hero', ['category_id' => $c, 'placement_code' => 'between_sections'])], null);

    expect(DB::table('home_blocks')->first())->toMatchArray(['block_type' => 'hero', 'category_id' => null, 'placement_code' => null]);
});

test('at most forty blocks', function () {
    $c = collect(range(1, 41))->map(fn ($i) => homeBlock('category', ['category_id' => insertCategory("c{$i}")]))->all();

    expect(layoutRefusal(fn () => $this->layout->save($c, null)))->toContain('عدد البلوكات كبير جداً (الحد 40).');
});

test('a change goes to the audit trail and tells the site after the commit; saving the same order does neither', function () {
    Queue::fake();
    config(['raqam.site_webhook.url' => 'https://site.example/hook', 'raqam.site_webhook.secret' => 'test-secret-0123456789']);

    $this->layout->save([homeBlock('hero'), homeBlock('latest')], $this->admin->id);
    Queue::assertPushed(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'home' && $job->payload['paths'] === ['/']);
    $audit = DB::table('audit_log')->where('action', 'settings')->latest('id')->first();
    expect($audit->actor_user_id)->toBe($this->admin->id)
        ->and(DB::table('audit_log_changes')->where('audit_id', $audit->id)->value('new_value'))->toBe("المادة الرئيسية\nآخر الأخبار");

    $this->layout->save($this->layout->load(), $this->admin->id);
    Queue::assertPushed(SendSiteWebhook::class, 1);
    expect(DB::table('audit_log')->where('action', 'settings')->count())->toBe(1);
});

// ---- the screen ------------------------------------------------------------------------------------------

test('the screen offers the suggested order until it is saved, then shows the stored one', function () {
    insertCategory('اقتصاد');

    Livewire::test(HomeLayoutEditor::class)->assertOk()->assertSee('ترتيب مقترح لم يُحفظ بعد')->assertSee('المادة الرئيسية')
        ->call('save')->assertNotified('تم حفظ ترتيب الرئيسية، وأُبلغ الموقع.');

    expect(DB::table('home_blocks')->count())->toBeGreaterThan(5);
    Livewire::test(HomeLayoutEditor::class)->assertDontSee('ترتيب مقترح لم يُحفظ بعد')->assertSee('اسحب البلوكات لترتيبها');
});

test('on the screen: reorder, hide, add a category block; a refusal is shown and nothing is saved', function () {
    $c = insertCategory('اقتصاد');
    $this->layout->save([homeBlock('hero'), homeBlock('latest')], null);
    $loaded = $this->layout->load();

    Livewire::test(HomeLayoutEditor::class)
        ->set('data.blocks', ['a' => $loaded[1], 'b' => [...$loaded[0], 'is_active' => false], 'c' => homeBlock('category', ['category_id' => $c])])
        ->call('save')->assertNotified();
    expect(storedOrder())->toBe(['latest', 'hero(hidden)', "category:{$c}"]);

    Livewire::test(HomeLayoutEditor::class)->set('data.blocks', ['a' => homeBlock('hero'), 'b' => homeBlock('hero')])->call('save')
        ->assertNotified('تعذّر الحفظ');
    expect(storedOrder())->toBe(['latest', 'hero(hidden)', "category:{$c}"]);
});

test('the screen can be moved with buttons as well as dragged (phones)', function () {
    $this->layout->save([homeBlock('hero'), homeBlock('latest')], null);
    $repeater = Livewire::test(HomeLayoutEditor::class)->instance()->form->getComponent('blocks');

    expect($repeater->isReorderableWithDragAndDrop())->toBeTrue()->and($repeater->isReorderableWithButtons())->toBeTrue();
});

test('the screen is for signed-in staff only', function () {
    auth()->logout();
    $this->get(HomeLayoutEditor::getUrl())->assertRedirect();
});
