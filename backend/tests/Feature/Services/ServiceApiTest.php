<?php

use App\Models\Service;
use App\Services\ServiceBoard;
use App\Services\ServiceCatalog;
use App\Settings\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    foreach (['contact.whatsapp' => '+970599123456', 'contact.phone' => '+97092345678', 'contact.email' => 'info@raqam.example', 'contact.whatsapp_message' => 'مرحباً، أود الاستفسار عن'] as $key => $value) {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'value_type' => str_contains($key, 'email') ? 'email' : (str_contains($key, 'message') ? 'string' : 'phone')]);
    }
});

function apiService(array $overrides = []): Service
{
    $s = app(ServiceCatalog::class)->save(null, array_merge([
        'group_id' => DB::table('service_groups')->where('name_ar', 'تغطيات إعلامية')->value('id'),
        'name_ar' => 'حلقة بودكاست', 'summary' => 'حلقة مصوّرة.', 'price_mode' => 'fixed', 'price' => '150', 'currency_code' => 'ILS', 'price_note' => 'للحلقة', 'is_active' => true,
        'deliverables' => ['تصوير'], 'faqs' => [['question' => 'متى؟', 'answer' => 'بعد أسبوع.', 'is_published' => true], ['question' => 'مخفي؟', 'answer' => 'نعم.', 'is_published' => false]],
    ], $overrides), null);
    Cache::increment('services:version');

    return $s;
}

function pressContact(string $slug, string $channel, array $headers = [], string $agent = PERSON_AGENT)
{
    return test()->flushHeaders()->withHeaders(['User-Agent' => $agent] + $headers)->postJson('/api/v1/services/'.rawurlencode($slug).'/contact', ['channel' => $channel]);
}

test('the prices page lists visible services by group with prices ready to show, in the contract shape', function () {
    $a = apiService(['name_ar' => 'قصة نجاح', 'price_mode' => 'from', 'price' => '1500', 'price_note' => null]);
    $b = apiService(['name_ar' => 'باقة البانر', 'group_id' => DB::table('service_groups')->where('name_ar', 'إعلانات')->value('id'), 'price_mode' => 'on_request']);
    apiService(['name_ar' => 'مخفية', 'is_active' => false]);
    $trashed = apiService(['name_ar' => 'محذوفة']);
    $trashed->delete();
    Cache::increment('services:version');

    $body = api('/services')->assertOk()->json();
    validateAgainstPath($body, '/services');
    expect(collect($body['data']['groups'])->pluck('name')->all())->toBe(['تغطيات إعلامية', 'إعلانات'])
        ->and(collect($body['data']['groups'][0]['services'])->pluck('name')->all())->toBe(['قصة نجاح'])
        ->and($body['data']['groups'][0]['services'][0]['price'])->toMatchArray(['mode' => 'from', 'amount' => '1500.00', 'currency' => 'ILS', 'symbol' => '₪', 'text' => 'يبدأ من 1,500.00 ₪', 'offer' => null])
        ->and($body['data']['groups'][1]['services'][0]['price'])->toMatchArray(['mode' => 'on_request', 'amount' => null, 'text' => 'السعر عند التواصل'])
        ->and($body['meta']['booking'])->toBe(['enabled' => false]);
});

test('a service page has the details, its published FAQ, published examples of its section and Service/Offer data', function () {
    ensurePostKinds();
    $section = DB::table('sections')->where('key', 'podcast')->value('id');
    $s = apiService(['section_id' => $section, 'description' => '<p>كل <strong>التفاصيل</strong></p>']);
    $episode = insertPost(publishedPostValues(['kind_code' => 'episode', 'title' => 'حلقة منشورة']));
    insertPost(['kind_code' => 'episode', 'title' => 'حلقة مسودة']);
    Cache::increment('services:version');

    $body = api('/services/'.rawurlencode($s->slug))->assertOk()->json();
    validateAgainstPath($body, '/services/{slug}');
    $d = $body['data'];
    expect($d['description_html'])->toBe('<p>كل <strong>التفاصيل</strong></p>')->and($d['deliverables'])->toBe(['تصوير'])
        ->and($d['faqs'])->toBe([['question' => 'متى؟', 'answer' => 'بعد أسبوع.']])
        ->and(collect($d['examples'])->pluck('title')->all())->toBe(['حلقة منشورة'])
        ->and($d['structured_data'])->toMatchArray(['@type' => 'Service', 'name' => 'حلقة بودكاست'])
        ->and($d['structured_data']['offers'])->toMatchArray(['@type' => 'Offer', 'price' => '150.00', 'priceCurrency' => 'ILS']);

    api('/services/no-such-service')->assertNotFound();
});

test('a price on request has no Offer in the structured data, and "from" states its lowest price', function () {
    $r = apiService(['name_ar' => 'عند الطلب', 'price_mode' => 'on_request']);
    $f = apiService(['name_ar' => 'يبدأ من', 'price_mode' => 'from', 'price' => '99.90', 'currency_code' => 'USD']);
    expect(api('/services/'.rawurlencode($r->slug))->json('data.structured_data'))->not->toHaveKey('offers')
        ->and(api('/services/'.rawurlencode($f->slug))->json('data.structured_data.offers'))->toMatchArray([
            'priceCurrency' => 'USD', 'priceSpecification' => ['@type' => 'PriceSpecification', 'minPrice' => '99.90', 'priceCurrency' => 'USD'],
        ]);
});

test('a special offer shows until its end and then the regular price comes back by itself', function () {
    $s = apiService(['offer' => true, 'sale_price' => '120', 'sale_ends_at' => now()->addMinutes(2)->format('Y-m-d H:i:s')]);
    $price = api('/services/'.rawurlencode($s->slug))->json('data.price');
    expect($price)->toMatchArray(['amount' => '120.00', 'text' => '120.00 ₪ للحلقة'])
        ->and($price['offer'])->toMatchArray(['was' => '150.00', 'was_text' => '150.00 ₪'])
        ->and(api('/services/'.rawurlencode($s->slug))->json('data.structured_data.offers.priceValidUntil'))->toBe(now()->addMinutes(2)->utc()->toDateString());

    // The cached answer lives at most until the offer ends…
    $ttl = (fn () => $this->ttl())->call(app(ServiceBoard::class));
    expect($ttl)->toBeLessThanOrEqual(120)->toBeGreaterThan(110);

    // …and after that the price is the regular one, with nobody touching anything.
    $this->travel(3)->minutes();
    Cache::flush();   // the array store does not expire by the test's clock for remember()
    expect(api('/services/'.rawurlencode($s->slug))->json('data.price'))->toMatchArray(['amount' => '150.00', 'offer' => null]);
});

test('the contact buttons carry the service name: WhatsApp message, call, e-mail subject', function () {
    DB::table('settings')->updateOrInsert(['key' => 'contact.email_subject'], ['value' => 'طلب خدمة', 'value_type' => 'string']);
    $s = apiService();
    $contact = api('/services/'.rawurlencode($s->slug))->json('data.contact');

    expect($contact['whatsapp'])->toBe('https://wa.me/970599123456?text='.rawurlencode('مرحباً، أود الاستفسار عن «حلقة بودكاست»'))
        ->and($contact['phone'])->toBe('tel:+97092345678')
        ->and($contact['email'])->toBe('mailto:info@raqam.example?subject='.rawurlencode('طلب خدمة: حلقة بودكاست'));

    DB::table('settings')->where('key', 'contact.whatsapp')->update(['value' => null]);
    Cache::increment('services:version');
    expect(api('/services/'.rawurlencode($s->slug))->json('data.contact.whatsapp'))->toBeNull();
});

test('an e-mail address with ? or & in it cannot add recipients to the link', function () {
    DB::table('settings')->where('key', 'contact.email')->update(['value' => 'info@raqam.example?bcc=spy']);
    $s = apiService();
    expect(api('/services/'.rawurlencode($s->slug))->json('data.contact.email'))->toStartWith('mailto:info@raqam.example%3Fbcc%3Dspy?subject=');
});

test('the booking button appears by itself when booking is switched on', function () {
    $s = apiService();
    expect(api('/services/'.rawurlencode($s->slug))->json('data.booking'))->toBe(['enabled' => false, 'path' => null]);

    app(FeatureFlags::class)->set('booking', true, null);
    Cache::increment('services:version');
    expect(api('/services/'.rawurlencode($s->slug))->json('data.booking'))->toBe(['enabled' => true, 'path' => '/services/'.$s->slug.'/book'])
        ->and(api('/services')->json('meta.booking'))->toBe(['enabled' => true]);
});

test('contact presses are counted per service and channel, once per 30 seconds, people only', function () {
    $s = apiService();
    pressContact($s->slug, 'whatsapp')->assertNoContent();
    pressContact($s->slug, 'whatsapp')->assertNoContent();     // the same press again
    pressContact($s->slug, 'phone')->assertNoContent();
    pressContact($s->slug, 'email', [], 'WhatsApp/2.23.20.0')->assertNoContent();
    $this->travel(31)->seconds();
    pressContact($s->slug, 'whatsapp')->assertNoContent();

    expect(DB::table('service_contact_clicks_daily')->where('service_id', $s->id)->pluck('clicks', 'channel')->all())->toBe(['whatsapp' => 2, 'phone' => 1])
        ->and(DB::table('service_contact_clicks_daily')->value('stat_date'))->toBe(now('Asia/Hebron')->toDateString());

    pressContact($s->slug, 'sms')->assertStatus(422);
    pressContact('no-such', 'phone')->assertNotFound();
    $this->withHeaders(['User-Agent' => PERSON_AGENT])->post('/api/v1/services/'.rawurlencode($s->slug).'/contact', ['channel' => 'phone'])->assertStatus(415);
});

test('a hidden service is not counted and not shown', function () {
    $s = apiService(['is_active' => false]);
    api('/services/'.rawurlencode($s->slug))->assertNotFound();
    pressContact($s->slug, 'phone')->assertNotFound();
});

test('a flood of contact presses is cut: an hourly ceiling per visitor and a per-minute limit', function () {
    $s = apiService();
    foreach (range(1, 31) as $i) {
        $this->travel(31)->seconds();
        pressContact($s->slug, 'phone')->assertNoContent();
    }
    expect((int) DB::table('service_contact_clicks_daily')->sum('clicks'))->toBe(30);
});

test('the advertise page has the slots that are on with their sizes, the ad packages with prices, and contact buttons', function () {
    $ads = DB::table('service_groups')->where('name_ar', 'إعلانات')->value('id');
    apiService(['name_ar' => 'باقة البانر', 'group_id' => $ads, 'price' => '400', 'price_note' => 'للشهر']);
    apiService(['name_ar' => 'ليست إعلاناً']);

    $body = api('/advertise')->assertOk()->json();
    validateAgainstPath($body, '/advertise');
    expect(collect($body['data']['placements'])->pluck('code')->all())->toBe(['header_leaderboard', 'sidebar_mpu', 'in_article', 'between_sections'])
        ->and($body['data']['placements'][0]['sizes']['desktop'])->toBe(['width' => 970, 'height' => 90])
        ->and(collect($body['data']['packages'])->pluck('name')->all())->toBe(['باقة البانر'])
        ->and($body['data']['packages'][0]['price']['text'])->toBe('400.00 ₪ للشهر')
        ->and($body['data']['contact']['whatsapp'])->toContain(rawurlencode('الإعلان على منصة رَقَم'))
        ->and($body['data']['booking'])->toBe(['enabled' => false]);
});

test('the counters in Redis hold no raw address', function () {
    // Covered for every counter by the same HMAC (TrackingHash::visitor); here the key itself is checked.
    $s = apiService();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44']);
    pressContact($s->slug, 'phone')->assertNoContent();
    $keys = array_keys((fn () => $this->storage)->call(Cache::store()->getStore()));
    expect(implode("\n", $keys))->not->toContain('198.51.100.44')->not->toContain(hash('sha256', '198.51.100.44'));
});
