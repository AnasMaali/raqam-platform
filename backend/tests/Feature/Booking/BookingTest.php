<?php

use App\Booking\BookingDesk;
use App\Booking\BookingLink;
use App\Booking\BookingRefused;
use App\Booking\BookingStatuses;
use App\Settings\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const BOOKING_SITE_TOKEN = 'booking-site-token';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => BOOKING_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example',
        'raqam.turnstile.secret' => 'turnstile-secret', 'raqam.turnstile.hostnames' => 'raqam.ps']);
    Cache::flush();
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    ensureBookingWorkflow();
    DB::table('feature_flags')->insertOrIgnore([
        ['key' => 'booking', 'description_ar' => 'نظام الحجز الإلكتروني', 'is_enabled' => false],
    ]);
    DB::table('feature_flags')->insertOrIgnore([
        ['key' => 'online_payments', 'description_ar' => 'الدفع الإلكتروني', 'is_enabled' => false, 'depends_on' => 'booking'],
    ]);
    $this->admin = staffAdmin('admin@raqam.example');
    $this->service = insertService(['slug' => 'podcast-episode', 'name_ar' => 'حلقة بودكاست', 'price_amount' => 12345, 'currency_code' => 'USD']);
    bookingTurnstile();
});

function bookingTurnstile(bool $success = true): void
{
    Http::swap(new Factory);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $success, 'action' => 'booking', 'hostname' => 'raqam.ps'])]);
}

function bookingOn(bool $payments = false): void
{
    DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => true]);
    if ($payments) {
        DB::table('feature_flags')->where('key', 'online_payments')->update(['is_enabled' => true]);
    }
}

function bookingForm(array $overrides = []): array
{
    return array_merge([
        'service' => 'podcast-episode', 'name' => 'سامي الخطيب', 'company' => 'مصنع الخطيب', 'phone' => '+970599123456', 'email' => 'sami@example.com',
        'preferred_contact' => 'whatsapp', 'preferred_dates' => 'صباح الأحد', 'details' => 'نريد حلقة عن تاريخ المصنع.', 'consent' => true,
        'turnstile_token' => 'tok', 'website' => '',
    ], $overrides);
}

function bookingHeaders(string $ip = '203.0.113.7'): array
{
    return ['X-Raqam-Site-Token' => BOOKING_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => $ip, 'User-Agent' => PERSON_AGENT];
}

function sendBooking(array $overrides = [], string $ip = '203.0.113.7', ?array $headers = null)
{
    return test()->flushHeaders()->withHeaders($headers ?? bookingHeaders($ip))->postJson('/api/v1/bookings', bookingForm($overrides));
}

function trackBooking(mixed $token, string $ip = '203.0.113.7')
{
    return test()->flushHeaders()->withHeaders(bookingHeaders($ip))->postJson('/api/v1/bookings/track', ['token' => $token]);
}

// --- Switched off (15.13, 15.14) -------------------------------------------------------------------------------------

test('while booking is off every booking route is a 404 and nothing is kept, however often it is asked', function () {
    foreach (range(1, 15) as $_) {   // more than the request limit: the switch answers before the limit counts
        sendBooking()->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
    trackBooking(BookingLink::newToken())->assertNotFound();
    test()->flushHeaders()->postJson('/api/v1/bookings', [])->assertNotFound();   // not even "site only" is revealed

    expect(DB::table('bookings')->count())->toBe(0)->and(DB::table('customers')->count())->toBe(0)->and(sentEmails())->toBe([]);
});

test('the service itself refuses a booking while the switch is off, whoever calls it', function () {
    expect(fn () => app(BookingDesk::class)->submit(bookingForm(), [], '203.0.113.7'))
        ->toThrow(BookingRefused::class, 'الحجز الإلكتروني غير متاح.');
    expect(DB::table('bookings')->count())->toBe(0);
});

test('«احجز الآن» is not offered while booking is off, and appears when it is on (15.1, 14.10)', function () {
    $this->getJson('/api/v1/services/podcast-episode')->assertOk()->assertJsonPath('data.booking', ['enabled' => false, 'path' => null]);

    app(FeatureFlags::class)->set('booking', true, $this->admin);   // the switch drops the services cache
    $this->getJson('/api/v1/services/podcast-episode')->assertOk()->assertJsonPath('data.booking', ['enabled' => true, 'path' => '/services/podcast-episode/book']);
});

// --- A request (15.2 – 15.6) ----------------------------------------------------------------------------------------

test('a booking gets its reference, the price of the moment, a private link and its first stage', function () {
    bookingOn();
    $response = sendBooking()->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');

    $year = now('Asia/Hebron')->year;
    $data = $response->json('data');
    expect($data['reference'])->toBe("RQ-{$year}-00001")
        ->and($data['token'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($data['url'])->toBe('https://raqam.example/bookings/track#'.$data['token']);

    $b = DB::table('bookings')->first();
    expect($b->price_amount)->toBe(12345)->and(trim($b->currency_code))->toBe('USD')
        ->and($b->access_token_hash)->toBe(hash('sha256', $data['token']))
        ->and($b->status_code)->toBe('new')->and($b->preferred_contact)->toBe('whatsapp')->and($b->consent_at)->not->toBeNull()
        ->and($b->ip_hash)->toMatch('/^[0-9a-f]{64}$/')->and($b->admin_notes)->toBeNull();
    // Neither the token nor the visitor's address is anywhere in the database.
    expect(json_encode(DB::table('bookings')->get()))->not->toContain($data['token'])->not->toContain('203.0.113.7');
    expect(DB::table('booking_status_history')->where('booking_id', $b->id)->pluck('status_code')->all())->toBe(['new']);
    expect(DB::table('customers')->first(['name', 'email', 'phone', 'company_name']))
        ->toEqual((object) ['name' => 'سامي الخطيب', 'email' => 'sami@example.com', 'phone' => '+970599123456', 'company_name' => 'مصنع الخطيب']);

    sendBooking(['email' => 'other@example.com'])->assertStatus(202)->assertJsonPath('data.reference', "RQ-{$year}-00002");
});

test('the price is fixed at the moment of booking: the offer while it lasts, nothing on request, and later changes do not touch it (15.5)', function () {
    bookingOn();
    DB::table('services')->where('id', $this->service)->update(['sale_price_amount' => 9999, 'sale_ends_at' => now()->addDay()]);
    sendBooking()->assertStatus(202);
    expect(DB::table('bookings')->orderByDesc('id')->value('price_amount'))->toBe(9999);

    DB::table('services')->where('id', $this->service)->update(['sale_price_amount' => null, 'sale_ends_at' => null, 'price_amount' => 123456, 'currency_code' => 'ILS']);
    expect(DB::table('bookings')->orderByDesc('id')->value('price_amount'))->toBe(9999);   // the earlier booking keeps its price
    sendBooking(['email' => 'b@example.com'])->assertStatus(202);
    expect(DB::table('bookings')->orderByDesc('id')->first(['price_amount', 'currency_code']))->toEqual((object) ['price_amount' => 123456, 'currency_code' => 'ILS']);

    insertService(['slug' => 'ask-us', 'price_mode' => 'on_request', 'price_amount' => null, 'currency_code' => null]);
    sendBooking(['service' => 'ask-us', 'email' => 'c@example.com'])->assertStatus(202);
    expect(DB::table('bookings')->orderByDesc('id')->first(['price_amount', 'currency_code']))->toEqual((object) ['price_amount' => null, 'currency_code' => null]);
});

test('a second request never overwrites the stored contact; the booking tells the admin what differs', function () {
    bookingOn();
    sendBooking()->assertStatus(202);
    sendBooking(['name' => 'منتحل', 'phone' => '+970591111111', 'company' => null], ip: '198.51.100.9')->assertStatus(202);

    expect(DB::table('customers')->count())->toBe(1)
        ->and(DB::table('customers')->first(['name', 'phone']))->toEqual((object) ['name' => 'سامي الخطيب', 'phone' => '+970599123456'])
        ->and(DB::table('bookings')->orderByDesc('id')->value('admin_notes'))
        ->toBe('بيانات هذا الطلب تختلف عن المسجّلة لهذا البريد: الاسم: منتحل، الهاتف: +970591111111.');
});

test('the form is checked strictly, and a refused one keeps nothing', function (array $bad, string $field) {
    bookingOn();
    insertService(['slug' => 'hidden', 'is_active' => false]);
    sendBooking($bad)->assertStatus(422)->assertJsonValidationErrors([$field], 'error.details');
    expect(DB::table('bookings')->count())->toBe(0)->and(DB::table('customers')->count())->toBe(0);
})->with([
    'no consent' => [['consent' => false], 'consent'],
    'a hidden service' => [['service' => 'hidden'], 'service'],
    'an unknown service' => [['service' => 'nope'], 'service'],
    'a local phone number' => [['phone' => '0599123456x'], 'phone'],
    'no phone' => [['phone' => ''], 'phone'],
    'a link in the name' => [['name' => 'visit spam.example'], 'name'],
    'a link in the company' => [['company' => 'https://spam.example'], 'company'],
    'an unknown contact way' => [['preferred_contact' => 'fax'], 'preferred_contact'],
    'too short details' => [['details' => 'قصير'], 'details'],
    'a bad e-mail' => [['email' => 'sami@'], 'email'],
]);

test('a robot that fills the honeypot is answered like a person and nothing is kept', function () {
    bookingOn();
    sendBooking(['website' => 'http://spam.example'])->assertStatus(202)->assertExactJson(['data' => ['received' => true]]);
    expect(DB::table('bookings')->count())->toBe(0)->and(sentEmails())->toBe([]);
});

test('only the site may send a booking, and Turnstile must agree (15.3)', function () {
    bookingOn();
    sendBooking(headers: ['X-Raqam-Visitor-Ip' => '203.0.113.7'])->assertStatus(403)->assertJsonPath('error.code', 'site_only');
    test()->flushHeaders()->withHeaders(bookingHeaders())->post('/api/v1/bookings', bookingForm())->assertStatus(415);

    bookingTurnstile(false);
    sendBooking()->assertStatus(422)->assertJsonPath('error.code', 'turnstile_failed');
    expect(DB::table('bookings')->count())->toBe(0);
});

test('one visitor (an IPv4 address or an IPv6 /64) gets 3 requests an hour', function () {
    bookingOn();
    foreach (['2001:db8:1:2::1', '2001:db8:1:2::abcd', '2001:db8:1:2:ffff::9'] as $i => $ip) {
        sendBooking(['email' => "v{$i}@example.com"], ip: $ip)->assertStatus(202);
    }
    sendBooking(['email' => 'v4@example.com'], ip: '2001:db8:1:2::77')->assertStatus(429)->assertJsonPath('error.code', 'too_many');
    sendBooking(['email' => 'v5@example.com'], ip: '2001:db8:1:3::1')->assertStatus(202);   // the next /64 is somebody else
    foreach (range(1, 3) as $i) {
        sendBooking(['email' => "w{$i}@example.com"], ip: '203.0.113.50')->assertStatus(202);
    }
    sendBooking(['email' => 'w4@example.com'], ip: '203.0.113.50')->assertStatus(429);
    expect(DB::table('bookings')->count())->toBe(7);
});

// --- Attachments (15.2) ---------------------------------------------------------------------------------------------

function sendBookingWithFiles(array $files, array $overrides = [])
{
    return test()->flushHeaders()->withHeaders([...bookingHeaders(), 'Content-Type' => 'multipart/form-data; boundary=x'])
        ->post('/api/v1/bookings', [...bookingForm($overrides), 'consent' => '1', 'attachments' => $files]);
}

test('up to 3 pictures or PDFs go with a booking, kept private and cleaned by the media library', function () {
    bookingOn();
    $files = [
        UploadedFile::fake()->createWithContent('logo.png', imageBytes(300, 200, 'png')),
        UploadedFile::fake()->createWithContent('brief.pdf', "%PDF-1.4\n%%EOF"),
        UploadedFile::fake()->createWithContent('photo.jpg', jpegWithExif(imageBytes(), gps: true)),
    ];
    sendBookingWithFiles($files)->assertStatus(202);

    $media = DB::table('booking_attachments as a')->join('media_assets as m', 'm.id', '=', 'a.media_id')->get(['m.visibility', 'm.mime_type', 'm.path']);
    expect($media)->toHaveCount(3)->and($media->pluck('visibility')->unique()->all())->toBe(['private'])
        ->and($media->pluck('mime_type')->sort()->values()->all())->toBe(['application/pdf', 'image/jpeg', 'image/png']);
    $jpeg = Storage::disk('media_local_private')->get($media->firstWhere('mime_type', 'image/jpeg')->path);
    expect(hasGps($jpeg))->toBeFalse();
});

test('a fourth file, a file of another type or a file too large refuses the whole booking', function (Closure $files) {
    bookingOn();
    config(['raqam.booking.attachment_bytes' => 50_000]);
    sendBookingWithFiles($files())->assertStatus(422);
    expect(DB::table('bookings')->count())->toBe(0)->and(DB::table('media_assets')->count())->toBe(0);
})->with([
    'four files' => [fn () => array_map(fn ($i) => UploadedFile::fake()->createWithContent("p{$i}.png", imageBytes(20 + $i, 20, 'png')), range(1, 4))],
    'an SVG' => [fn () => [UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]],
    'a script named .pdf' => [fn () => [UploadedFile::fake()->createWithContent('x.pdf', '<?php echo 1;')]],
    'too large' => [fn () => [UploadedFile::fake()->createWithContent('big.png', imageBytes(900, 900, 'png', noise: true))]],
]);

// --- Mail (15.6) ----------------------------------------------------------------------------------------------------

test('the customer gets the confirmation with the private link, and the admins the alert with the request', function () {
    bookingOn();
    $token = sendBooking()->json('data.token');

    $mail = sentEmailsTo('sami@example.com');
    expect($mail)->toHaveCount(1)->and($mail[0]->getSubject())->toBe('وصلنا طلب حجزك RQ-'.now('Asia/Hebron')->year.'-00001')
        ->and($mail[0]->getHtmlBody())->toContain('https://raqam.example/bookings/track#'.$token)->toContain('حلقة بودكاست');

    $alert = sentEmailsTo('admin@raqam.example');
    expect($alert)->toHaveCount(1)->and($alert[0]->getReplyTo()[0]->getAddress())->toBe('sami@example.com')
        ->and($alert[0]->getHtmlBody())->toContain('نريد حلقة عن تاريخ المصنع.')->toContain('واتساب')->not->toContain($token);
    expect(DB::table('admin_notifications')->where('user_id', $this->admin)->first(['category', 'title', 'action_path']))
        ->toEqual((object) ['category' => 'messages', 'title' => 'طلب حجز جديد RQ-'.now('Asia/Hebron')->year.'-00001', 'action_path' => null]);
});

test('one address gets at most 3 confirmations a day, whoever asks', function () {
    bookingOn();
    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'] as $ip) {
        sendBooking(ip: $ip)->assertStatus(202);
    }
    expect(DB::table('bookings')->count())->toBe(4)->and(sentEmailsTo('sami@example.com'))->toHaveCount(3);
});

// --- The status page (15.9) -----------------------------------------------------------------------------------------

test('the private link shows the progress and nothing the customer typed or the admin noted', function () {
    bookingOn();
    $token = sendBooking()->json('data.token');
    $id = DB::table('bookings')->value('id');
    DB::table('bookings')->where('id', $id)->update(['admin_notes' => 'ملاحظة داخلية']);
    app(BookingStatuses::class)->move($id, 'contacted', 'اتصلنا به');
    app(BookingStatuses::class)->move($id, 'contacted', 'ملاحظة فقط');

    $data = trackBooking($token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($data['reference'])->toBe('RQ-'.now('Asia/Hebron')->year.'-00001')->and($data['service'])->toBe('حلقة بودكاست')
        ->and($data['status'])->toBe(['code' => 'contacted', 'name' => 'تم التواصل', 'final' => false])
        ->and(array_column($data['history'], 'code'))->toBe(['new', 'contacted']);
    expect(json_encode($data, JSON_UNESCAPED_UNICODE))->not->toContain('sami')->not->toContain('+970')->not->toContain('ملاحظة')
        ->not->toContain('نريد حلقة')->not->toContain('اتصلنا');
});

test('a wrong, malformed or deleted booking token is a plain 404', function () {
    bookingOn();
    $token = sendBooking()->json('data.token');
    trackBooking(BookingLink::newToken())->assertNotFound();
    trackBooking('short')->assertNotFound();
    trackBooking(['array'])->assertNotFound();
    trackBooking(strtoupper($token) === $token ? strtolower($token) : strtoupper($token))->assertNotFound();
    DB::table('bookings')->update(['deleted_at' => now()]);
    trackBooking($token)->assertNotFound();
});

// --- Stages (15.7, 15.8, 15.11, 15.12) ------------------------------------------------------------------------------

test('a stage changes only by a history row, along the allowed moves, and the customer is told when the stage says so', function () {
    bookingOn();
    sendBooking()->assertStatus(202);
    $id = DB::table('bookings')->value('id');
    $statuses = app(BookingStatuses::class);
    $before = count(sentEmailsTo('sami@example.com'));

    $statuses->move($id, 'contacted', null, $this->admin);     // notify_customer = false
    expect(count(sentEmailsTo('sami@example.com')))->toBe($before);
    $statuses->move($id, 'confirmed', 'تم الاتفاق', $this->admin);
    $mails = sentEmailsTo('sami@example.com');
    expect($mails)->toHaveCount($before + 1)->and(end($mails)->getHtmlBody())->toContain('مؤكد');

    $rows = DB::table('booking_status_history')->where('booking_id', $id)->orderBy('id')->get(['status_code', 'changed_by', 'note', 'customer_notified_at']);
    expect($rows->pluck('status_code')->all())->toBe(['new', 'contacted', 'confirmed'])
        ->and($rows[1]->customer_notified_at)->toBeNull()->and($rows[2]->customer_notified_at)->not->toBeNull()
        ->and($rows[2]->changed_by)->toBe($this->admin)->and($rows[2]->note)->toBe('تم الاتفاق');
    expect(DB::table('bookings')->value('status_code'))->toBe('confirmed');

    expect(fn () => $statuses->move($id, 'new'))->toThrow(BookingRefused::class, 'لا يمكن نقل الحجز إلى مرحلة «جديد» من مرحلته الحالية.');
    $statuses->move($id, 'cancelled');
    expect(fn () => $statuses->move($id, 'scheduled'))->toThrow(BookingRefused::class);   // cancelled is the end
    expect(DB::table('bookings')->value('status_code'))->toBe('cancelled');
});

test('the payment stages exist only while online payments are on (15.12)', function () {
    bookingOn();
    sendBooking()->assertStatus(202);
    $id = DB::table('bookings')->value('id');

    expect(fn () => app(BookingStatuses::class)->move($id, 'awaiting_payment'))->toThrow(BookingRefused::class, 'مراحل الدفع غير متاحة والدفع الإلكتروني مطفأ.');
    expect(DB::table('bookings')->value('status_code'))->toBe('new');

    bookingOn(payments: true);
    app(BookingStatuses::class)->move($id, 'awaiting_payment');
    expect(DB::table('bookings')->value('status_code'))->toBe('awaiting_payment');
});

test('an unknown stage or booking is refused', function () {
    bookingOn();
    expect(fn () => app(BookingStatuses::class)->move(999, 'contacted'))->toThrow(BookingRefused::class, 'الحجز غير موجود.');
    expect(fn () => app(BookingStatuses::class)->move(999, 'nope'))->toThrow(BookingRefused::class, 'هذه المرحلة غير موجودة.');
});

test('only a string of a token\'s exact shape is looked up at all', function () {
    expect(BookingLink::wellFormed(BookingLink::newToken()))->toBeTrue()
        ->and(BookingLink::wellFormed(str_repeat('a', 42)))->toBeFalse()
        ->and(BookingLink::wellFormed(str_repeat('a', 44)))->toBeFalse()
        ->and(BookingLink::wellFormed(str_repeat('a', 42).'='))->toBeFalse()
        ->and(BookingLink::wellFormed(str_repeat('a', 43)."\n"))->toBeFalse()
        ->and(BookingLink::wellFormed(['x']))->toBeFalse();
});
