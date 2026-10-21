<?php

use App\Jobs\SendSiteWebhook;
use App\Models\Service;
use App\Services\Money;
use App\Services\PriceRejected;
use App\Services\ServiceCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function serviceData(array $overrides = []): array
{
    return array_merge([
        'group_id' => DB::table('service_groups')->where('name_ar', 'تغطيات إعلامية')->value('id'),
        'name_ar' => 'حلقة بودكاست', 'slug' => '', 'summary' => 'حلقة مصوّرة مع ضيف.', 'description' => '<p>تفاصيل <strong>الحلقة</strong></p>',
        'price_mode' => 'fixed', 'price' => '150', 'currency_code' => 'ILS', 'price_note' => 'للحلقة', 'is_active' => true,
        'deliverables' => ['تصوير', 'مونتاج'], 'faqs' => [['question' => 'كم مدة الحلقة؟', 'answer' => 'نحو ساعة.', 'is_published' => true]],
    ], $overrides);
}

function saveService(array $overrides = [], ?Service $service = null): Service
{
    return app(ServiceCatalog::class)->save($service, serviceData($overrides), null);
}

function serviceRefusal(array $overrides, ?Service $service = null): string
{
    try {
        saveService($overrides, $service);
    } catch (PriceRejected $e) {
        return $e->field.': '.$e->getMessage();
    }

    return 'saved';
}

// ---- money -----------------------------------------------------------------------------------------------

test('a typed price becomes whole agorot or cents, exactly', function (string $typed, int $minor) {
    expect(Money::toMinor($typed))->toBe($minor);
})->with([
    ['16.12', 1612], ['150', 15000], ['150.5', 15050], ['0.01', 1], ['1000000', 100000000], ['999999.99', 99999999], [' 16.12 ', 1612],
    ['١٦٫١٢', 1612], ['٢٠٠', 20000], ['0016.10', 1610],
]);

test('negative, huge, fractional beyond cents, zero and anything not a plain number are refused, never rounded', function (string $typed) {
    expect(fn () => Money::toMinor($typed))->toThrow(PriceRejected::class);
})->with(['-5', '-0.01', '16.123', '16.129', '0', '0.00', '1000000.01', '99999999999', '1e3', '1,500', '1 500', '١،٥٠٠', '$16', '16$', '', '.5', '16.', 'NaN', 'INF', '0x10', '16.12.1', '+16']);

test('amounts read back with their decimals and show with thousands separators', function () {
    expect(Money::fromMinor(1612))->toBe('16.12')->and(Money::fromMinor(15000))->toBe('150.00')->and(Money::fromMinor(1))->toBe('0.01')
        ->and(Money::display(150000))->toBe('1,500.00')->and(Money::display(99999999))->toBe('999,999.99')->and(Money::fromMinor(1612, 3))->toBe('1.612');
});

// ---- saving ----------------------------------------------------------------------------------------------

test('a service is saved with its price in agorot, what it includes and its FAQ', function () {
    $s = saveService(['price' => '16.12']);

    expect($s->only(['price_mode', 'price_amount', 'currency_code', 'price_note', 'slug', 'is_active']))
        ->toBe(['price_mode' => 'fixed', 'price_amount' => 1612, 'currency_code' => 'ILS', 'price_note' => 'للحلقة', 'slug' => 'حلقة-بودكاست', 'is_active' => true])
        ->and($s->deliverables())->toBe(['تصوير', 'مونتاج'])
        ->and(DB::table('faqs')->where('service_id', $s->id)->value('question'))->toBe('كم مدة الحلقة؟')
        ->and($s->description)->toBe('<p>تفاصيل <strong>الحلقة</strong></p>');
});

test('the description is cleaned like any text of the site', function () {
    $s = saveService(['description' => '<p onclick="x()">نص<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">رابط</a></p>']);
    expect($s->description)->not->toContain('script')->not->toContain('onclick')->not->toContain('<img')->not->toContain('javascript:');
});

test('a price on request carries no amount, currency, note or offer', function () {
    $s = saveService(['price_mode' => 'on_request', 'price' => '150', 'offer' => true, 'sale_price' => '100', 'sale_ends_at' => now()->addDay()->format('Y-m-d H:i:s')]);
    expect($s->only(['price_amount', 'currency_code', 'price_note', 'sale_price_amount', 'sale_ends_at']))->each->toBeNull();
});

test('the price must be money in shekels or dollars', function () {
    expect(serviceRefusal(['price' => '-150']))->toStartWith('price: المبلغ لا يكون سالباً')
        ->and(serviceRefusal(['price' => '150.555']))->toStartWith('price: المبلغ بمنزلتين')
        ->and(serviceRefusal(['price' => '5000000']))->toStartWith('price: المبلغ أكبر من المسموح')
        ->and(serviceRefusal(['price' => '']))->toStartWith('price:')
        ->and(serviceRefusal(['currency_code' => 'JOD']))->toStartWith('currency_code:')
        ->and(serviceRefusal(['currency_code' => 'EUR']))->toStartWith('currency_code:')
        ->and(serviceRefusal(['currency_code' => 'USD', 'price' => '99.99']))->toBe('saved')
        ->and(serviceRefusal(['price_mode' => 'free']))->toStartWith('price_mode:')
        ->and(serviceRefusal(['price_note' => str_repeat('ح', 81)]))->toStartWith('price_note:');
});

test('a special offer is cheaper than the price, ends in the future and within a year', function () {
    $ok = ['offer' => true, 'sale_price' => '120', 'sale_ends_at' => now()->addDays(3)->format('Y-m-d H:i:s')];
    expect(serviceRefusal($ok))->toBe('saved')
        ->and(serviceRefusal(['sale_price' => '150'] + $ok))->toStartWith('sale_price: سعر العرض يجب أن يكون أقل')
        ->and(serviceRefusal(['sale_price' => '200'] + $ok))->toStartWith('sale_price:')
        ->and(serviceRefusal(['sale_price' => '-1'] + $ok))->toStartWith('sale_price:')
        ->and(serviceRefusal(['sale_price' => '0'] + $ok))->toStartWith('sale_price:')
        ->and(serviceRefusal(['sale_ends_at' => now()->subMinute()->format('Y-m-d H:i:s')] + $ok))->toStartWith('sale_ends_at:')
        ->and(serviceRefusal(['sale_ends_at' => null] + $ok))->toStartWith('sale_ends_at:')
        ->and(serviceRefusal(['sale_ends_at' => now()->addYears(2)->format('Y-m-d H:i:s')] + $ok))->toStartWith('sale_ends_at:');
});

test('the database keeps the last word on prices: a negative amount or an offer above the price cannot be stored', function () {
    $s = saveService();
    expect(rejected(fn () => DB::table('services')->where('id', $s->id)->update(['price_amount' => -1])))->toThrow(QueryException::class)
        ->and(rejected(fn () => DB::table('services')->where('id', $s->id)->update(['sale_price_amount' => 20000, 'sale_ends_at' => now()->addDay()])))->toThrow(QueryException::class);
});

test('the other fields are checked: name, summary, group, section, picture, lists', function () {
    expect(serviceRefusal(['name_ar' => '']))->toStartWith('name_ar:')
        ->and(serviceRefusal(['summary' => str_repeat('ح', 301)]))->toStartWith('summary:')
        ->and(serviceRefusal(['group_id' => 999]))->toStartWith('group_id:')
        ->and(serviceRefusal(['section_id' => 999]))->toStartWith('section_id:')
        ->and(serviceRefusal(['cover_media_id' => insertMedia(['visibility' => 'private'])]))->toStartWith('cover_media_id:')
        ->and(serviceRefusal(['cover_media_id' => '1; DROP TABLE services']))->toStartWith('cover_media_id:')
        ->and(serviceRefusal(['deliverables' => array_fill(0, 21, 'بند')]))->toStartWith('deliverables:')
        ->and(serviceRefusal(['deliverables' => [str_repeat('ب', 201)]]))->toStartWith('deliverables:')
        ->and(serviceRefusal(['faqs' => [['question' => 'سؤال بلا جواب', 'answer' => '']]]))->toStartWith('faqs:')
        ->and(serviceRefusal(['cover_media_id' => insertMedia()]))->toBe('saved');
});

// ---- audit -----------------------------------------------------------------------------------------------

test('every price change is in the audit log, from how much to how much', function () {
    $s = saveService(['price' => '150']);
    saveService(['price' => '175.50'], $s);
    $ends = now()->addMonth()->setTime(8, 0);
    saveService(['price' => '175.50', 'offer' => true, 'sale_price' => '120', 'sale_ends_at' => $ends->format('Y-m-d H:i:s')], $s->refresh());

    $rows = DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_log_changes.audit_id')
        ->where('audit_log.subject_table', 'services')->where('audit_log_changes.field', 'price')->orderBy('audit_log.id')
        ->get(['old_value', 'new_value'])->map(fn ($r) => [$r->old_value, $r->new_value])->all();
    expect($rows)->toBe([
        [null, '150.00 ILS (سعر ثابت) للحلقة'],
        ['150.00 ILS (سعر ثابت) للحلقة', '175.50 ILS (سعر ثابت) للحلقة'],
        ['175.50 ILS (سعر ثابت) للحلقة', '175.50 ILS (سعر ثابت) للحلقة — عرض 120.00 حتى '.$ends->copy()->setTimezone('Asia/Hebron')->format('Y-m-d H:i')],
    ]);
    expect(DB::table('audit_log_changes')->join('audit_log', 'audit_log.id', '=', 'audit_log_changes.audit_id')
        ->where('audit_log.subject_table', 'services')->where('field', 'price_amount')->orderByDesc('audit_log.id')->first(['old_value', 'new_value']))
        ->toEqual((object) ['old_value' => '15000', 'new_value' => '17550']);
});

test('changes to what a service includes and to its FAQ are audited too, and the site is told once per save', function () {
    config(['raqam.site_webhook.url' => 'https://site.test/hook', 'raqam.site_webhook.secret' => 'secret']);
    Queue::fake([SendSiteWebhook::class]);
    $s = saveService();
    Queue::assertPushed(SendSiteWebhook::class, 1);
    saveService(['deliverables' => ['تصوير', 'مونتاج', 'نشر']], $s);

    expect(DB::table('audit_log_changes')->where('field', 'deliverables')->orderByDesc('audit_id')->value('new_value'))->toBe("تصوير\nمونتاج\nنشر");
    Queue::assertPushed(SendSiteWebhook::class, 2);
    Queue::assertPushed(SendSiteWebhook::class, fn ($job) => $job->payload['type'] === 'services' && in_array('/services/'.$s->slug, $job->payload['paths'], true));
});

// ---- install ---------------------------------------------------------------------------------------------

test('services:install adds the six starting services hidden and without prices, and never touches existing ones', function () {
    ensurePostKinds();
    $mine = saveService(['name_ar' => 'قصة نجاح', 'price' => '300']);

    $this->artisan('services:install')->expectsOutputToContain('skipped: قصة نجاح')->assertSuccessful();
    $this->artisan('services:install')->expectsOutputToContain('Services added: 0')->assertSuccessful();

    expect(Service::query()->count())->toBe(6)
        ->and(Service::query()->whereKeyNot($mine->id)->pluck('price_mode')->unique()->all())->toBe(['on_request'])
        ->and(Service::query()->whereKeyNot($mine->id)->where('is_active', true)->count())->toBe(0)
        ->and(Service::query()->whereNotNull('price_amount')->pluck('id')->all())->toBe([$mine->id])
        ->and($mine->refresh()->price_amount)->toBe(30000)
        ->and(Service::query()->where('slug', 'ad-packages')->first()->group->name_ar)->toBe('إعلانات')
        ->and(Service::query()->where('slug', 'podcast-episode')->value('section_id'))->toBe(DB::table('sections')->where('key', 'podcast')->value('id'));
});
