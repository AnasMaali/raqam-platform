<?php

use App\Filament\Pages\GeneralSettings;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceCatalog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
    $this->group = DB::table('service_groups')->where('name_ar', 'تغطيات إعلامية')->value('id');
});

function screenService(array $overrides = []): Service
{
    return app(ServiceCatalog::class)->save(null, array_merge([
        'group_id' => test()->group, 'name_ar' => 'قصة نجاح', 'summary' => 'قصة.', 'price_mode' => 'fixed', 'price' => '300', 'currency_code' => 'USD', 'is_active' => true,
    ], $overrides), null);
}

test('a service with a price, what it includes, an FAQ and an offer is created from the screen', function () {
    Livewire::test(CreateService::class)
        ->fillForm([
            'group_id' => $this->group, 'name_ar' => 'حلقة بودكاست', 'summary' => 'حلقة مصوّرة.', 'is_active' => true,
            'price_mode' => 'fixed', 'price' => '16.12', 'currency_code' => 'ILS', 'price_note' => 'للحلقة',
            'offer' => true, 'sale_price' => '12.50', 'sale_ends_at' => now('Asia/Hebron')->addDays(2)->format('Y-m-d H:i:s'),
            'deliverables' => [['label' => 'تصوير'], ['label' => 'مونتاج']], 'faqs' => [['question' => 'متى؟', 'answer' => 'قريباً.', 'is_published' => true]],
        ])
        ->call('create')->assertHasNoFormErrors();

    $s = Service::query()->where('name_ar', 'حلقة بودكاست')->firstOrFail();
    expect($s->only(['price_amount', 'sale_price_amount', 'currency_code', 'slug']))->toBe(['price_amount' => 1612, 'sale_price_amount' => 1250, 'currency_code' => 'ILS', 'slug' => 'حلقة-بودكاست'])
        ->and($s->deliverables())->toBe(['تصوير', 'مونتاج'])
        // Typed in Palestine time, stored in UTC.
        ->and($s->sale_ends_at->setTimezone('Asia/Hebron')->format('Y-m-d H'))->toBe(now('Asia/Hebron')->addDays(2)->format('Y-m-d H'));
});

test('a wrong price is refused under its field and nothing is saved', function (string $field, string $value) {
    Livewire::test(CreateService::class)
        ->fillForm(array_merge(['group_id' => $this->group, 'name_ar' => 'خدمة', 'summary' => 'وصف.', 'price_mode' => 'fixed', 'price' => '100', 'currency_code' => 'ILS',
            'offer' => $field === 'sale_price', 'sale_price' => '50', 'sale_ends_at' => now('Asia/Hebron')->addDay()->format('Y-m-d H:i:s')], [$field => $value]))
        ->call('create')->assertHasFormErrors([$field]);
    expect(Service::query()->count())->toBe(0);
})->with([['price', '-100'], ['price', '100.001'], ['price', '9999999'], ['price', '1e5'], ['price', 'مئة'], ['sale_price', '100'], ['sale_price', '150']]);

test('editing shows the price as money; an offer that has ended shows empty, with a note, and the save keeps it off', function () {
    $s = screenService();
    DB::table('services')->where('id', $s->id)->update(['sale_price_amount' => 25000, 'sale_ends_at' => now()->subHour()]);

    Livewire::test(EditService::class, ['record' => $s->id])
        ->assertFormSet(['price' => '300.00', 'offer' => false, 'sale_price' => null])
        ->assertSee('العرض السابق انتهى في')
        ->fillForm(['price' => '320'])->call('save')->assertHasNoFormErrors();
    expect($s->refresh()->only(['price_amount', 'sale_price_amount', 'sale_ends_at']))->toBe(['price_amount' => 32000, 'sale_price_amount' => null, 'sale_ends_at' => null]);
});

test('the list shows prices, offers and contact presses of the last 30 days; dragging drops the cached order', function () {
    $a = screenService(['name_ar' => 'أ', 'offer' => true, 'sale_price' => '250', 'sale_ends_at' => now()->addDay()->format('Y-m-d H:i:s')]);
    $b = screenService(['name_ar' => 'ب', 'price_mode' => 'on_request']);
    DB::table('service_contact_clicks_daily')->insert([
        ['service_id' => $a->id, 'channel' => 'whatsapp', 'stat_date' => now('Asia/Hebron')->toDateString(), 'clicks' => 7],
        ['service_id' => $a->id, 'channel' => 'phone', 'stat_date' => now('Asia/Hebron')->subDays(40)->toDateString(), 'clicks' => 100],
    ]);

    Livewire::test(ListServices::class)->assertSee('250.00 $')->assertSee('كان 300.00 $')->assertSee('السعر عند التواصل')->assertSee('7')->assertDontSee('107');

    $before = (int) Cache::get('services:version', 0);
    Livewire::test(ListServices::class)->call('reorderTable', [$b->id, $a->id]);
    expect($b->refresh()->sort_order)->toBeLessThan($a->refresh()->sort_order)->and((int) Cache::get('services:version', 0))->toBeGreaterThan($before);
});

test('deleting a service moves it to the trash, off the site, and it can come back', function () {
    $s = screenService();
    Livewire::test(ListServices::class)->callAction(TestAction::make('delete')->table($s));
    expect(Service::query()->find($s->id))->toBeNull()->and(Service::withTrashed()->find($s->id))->not->toBeNull();
    api('/services/'.rawurlencode($s->slug))->assertNotFound();
});

test('the e-mail subject of the contact button is a general setting', function () {
    Livewire::test(GeneralSettings::class)->fillForm(['contact__email_subject' => 'طلب خدمة'])->call('save')->assertHasNoFormErrors();
    expect(DB::table('settings')->where('key', 'contact.email_subject')->value('value'))->toBe('طلب خدمة');
});

test('saving from the edit screen keeps what the service includes and its FAQ as they were', function () {
    $s = screenService(['deliverables' => ['تصوير', 'مونتاج'], 'faqs' => [['question' => 'متى؟', 'answer' => 'قريباً.', 'is_published' => false]]]);

    $t = Livewire::test(EditService::class, ['record' => $s->id]);
    expect(array_values($t->get('data.deliverables')))->toBe([['label' => 'تصوير'], ['label' => 'مونتاج']]);
    $t->call('save')->assertHasNoFormErrors();

    expect($s->deliverables())->toBe(['تصوير', 'مونتاج'])
        ->and(DB::table('faqs')->where('service_id', $s->id)->get(['question', 'is_published'])->map(fn ($f) => (array) $f)->all())->toBe([['question' => 'متى؟', 'is_published' => false]]);
});
