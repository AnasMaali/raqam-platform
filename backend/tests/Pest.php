<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/Support/media.php';
require_once __DIR__.'/Support/api.php';
require_once __DIR__.'/Support/search.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// A failing statement aborts a PostgreSQL transaction; the savepoint keeps the test's own transaction usable.
function rejected(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

// Reference rows exactly as database/schema/12-seed-reference.sql inserts them. Rows the seed already
// holds are skipped, so every test sees the same state before and after the seed migration.
function ensure(string $table, array ...$rows): void
{
    DB::table($table)->insertOrIgnore($rows);
}

function insertUser(string $type, string $email, array $extra = []): int
{
    return DB::table('users')->insertGetId([
        'user_type' => $type, 'name' => 'User', 'email' => $email, 'password_hash' => 'x', ...$extra,
    ]);
}

// A valid public JPEG; tests override only the column they exercise.
function insertMedia(array $overrides = []): int
{
    static $n = 0;
    $n++;

    return DB::table('media_assets')->insertGetId([
        'path' => "test/{$n}.jpg", 'original_name' => "{$n}.jpg", 'mime_type' => 'image/jpeg',
        'size_bytes' => 1000, 'checksum_sha256' => hash('sha256', (string) $n), 'alt_text' => 'صورة',
        'width' => 800, 'height' => 600, ...$overrides,
    ]);
}

function insertCategory(string $slug, ?int $parent = null): int
{
    return DB::table('categories')->insertGetId(['parent_id' => $parent, 'name_ar' => $slug, 'slug' => $slug]);
}

function insertYoutubeVideo(string $youtubeId): int
{
    $video = DB::table('videos')->insertGetId(['source' => 'youtube']);
    DB::table('youtube_videos')->insert(['video_id' => $video, 'youtube_id' => $youtubeId, 'source_title' => 'حلقة']);

    return $video;
}

function insertUploadedVideo(): int
{
    return DB::table('videos')->insertGetId([
        'source' => 'upload', 'display_title' => 'مقطع',
        'upload_media_id' => insertMedia(['mime_type' => 'video/mp4', 'alt_text' => null, 'duration_seconds' => 30, 'width' => 1080, 'height' => 1920]),
    ]);
}

// The 7 sections and 11 post kinds, as database/schema/12-seed-reference.sql inserts them.
function ensurePostKinds(): void
{
    DB::statement(<<<'SQL'
        INSERT INTO sections (key, name_ar, slug, sort_order) VALUES
          ('podcast',         'البودكاست',              'podcast',          1),
          ('success_stories', 'قصص نجاح',               'success-stories',  2),
          ('made_in_nablus',  'صُنع في نابلس',           'made-in-nablus',   3),
          ('nablus_history',  'تاريخ نابلس الاقتصادي',  'history',          4),
          ('initiatives',     'مبادرات اقتصادية',       'initiatives',      5),
          ('round_table',     'طاولة مستديرة',          'round-table',      6),
          ('awareness',       'التوعية',                 'awareness',        7)
        ON CONFLICT DO NOTHING
        SQL);
    DB::statement(<<<'SQL'
        INSERT INTO post_kinds (code, name_ar, section_id, requires_category, schema_org_type, sort_order)
        SELECT k.code, k.name_ar, s.id, k.requires_category, k.schema_org_type, k.sort_order
        FROM (VALUES
          ('news',        'خبر',             NULL,              true,  'NewsArticle',          1),
          ('report',      'تقرير',           NULL,              true,  'ReportageNewsArticle', 2),
          ('opinion',     'مقال رأي',        NULL,              true,  'OpinionNewsArticle',   3),
          ('infographic', 'إنفوغراف',        NULL,              true,  'NewsArticle',          4),
          ('episode',     'حلقة بودكاست',    'podcast',         false, 'Article',              5),
          ('story',       'قصة نجاح',        'success_stories', false, 'Article',              6),
          ('product',     'منتج',            'made_in_nablus',  false, 'Article',              7),
          ('history',     'تاريخ',           'nablus_history',  false, 'Article',              8),
          ('initiative',  'مبادرة',          'initiatives',     false, 'Article',              9),
          ('roundtable',  'جلسة طاولة مستديرة', 'round_table',  false, 'Article',             10),
          ('awareness',   'بطاقة توعية',     'awareness',       false, 'Article',             11)
        ) AS k(code, name_ar, section_key, requires_category, schema_org_type, sort_order)
        LEFT JOIN sections s ON s.key = k.section_key
        ON CONFLICT DO NOTHING
        SQL);
}

// A draft post; tests override only what they exercise.
function insertPost(array $overrides = []): int
{
    static $n = 0;
    $n++;
    ensurePostKinds();

    return DB::table('posts')->insertGetId(['kind_code' => 'news', 'title' => "خبر {$n}", 'slug' => "خبر-{$n}", ...$overrides]);
}

// A post that satisfies every publishing rule of a news kind.
function publishedPostValues(array $overrides = []): array
{
    static $n = 0;
    $n++;

    return [
        'category_id' => insertCategory("تصنيف-{$n}"), 'body' => '<p>نص</p>', 'status' => 'published',
        'published_at' => now(), 'cover_media_id' => insertMedia(), ...$overrides,
    ];
}

// The currencies and the two data providers that rates and weather rows reference, as seeded.
function ensureCurrencies(): void
{
    ensure('currencies',
        ['code' => 'ILS', 'name_ar' => 'شيكل إسرائيلي', 'symbol' => '₪', 'minor_units' => 2],
        ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'symbol' => '$', 'minor_units' => 2],
        ['code' => 'JOD', 'name_ar' => 'دينار أردني', 'symbol' => 'د.أ', 'minor_units' => 3],
        ['code' => 'AED', 'name_ar' => 'درهم إماراتي', 'symbol' => 'د.إ', 'minor_units' => 2],
        ['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'symbol' => 'ر.س', 'minor_units' => 2],
    );
    ensure('data_providers',
        ['code' => 'bank_of_israel', 'service' => 'currency', 'name' => 'Bank of Israel representative rates (official, free)', 'is_active' => true, 'is_backup' => false],
        ['code' => 'openweathermap', 'service' => 'weather', 'name' => 'OpenWeatherMap (free)', 'is_active' => true, 'is_backup' => false],
    );
}

function insertService(array $overrides = []): int
{
    ensureCurrencies();
    ensure('service_groups', ['name_ar' => 'تغطيات إعلامية', 'sort_order' => 1]);

    return DB::table('services')->insertGetId([
        'group_id' => DB::table('service_groups')->where('name_ar', 'تغطيات إعلامية')->value('id'),
        'name_ar' => 'حلقة بودكاست', 'slug' => 'service-'.uniqid(), 'summary' => 'استضافة في حلقة',
        'price_mode' => 'fixed', 'price_amount' => 30000, 'currency_code' => 'USD', ...$overrides,
    ]);
}

function ensureBookingWorkflow(): void
{
    DB::statement(<<<'SQL'
        INSERT INTO booking_statuses (code, name_ar, sort_order, is_terminal, notify_customer, requires_payment_flag) VALUES
          ('new',              'جديد',            1, false, false, false),
          ('awaiting_payment', 'بانتظار الدفع',    2, false, true,  true),
          ('paid',             'مدفوع',           3, false, true,  true),
          ('contacted',        'تم التواصل',      4, false, false, false),
          ('confirmed',        'مؤكد',            5, false, true,  false),
          ('scheduled',        'مجدول',           6, false, true,  false),
          ('completed',        'منفّذ',            7, false, true,  false),
          ('published',        'منشور',           8, true,  true,  false),
          ('cancelled',        'ملغي',            9, true,  true,  false)
        ON CONFLICT DO NOTHING
        SQL);
    DB::statement(<<<'SQL'
        INSERT INTO booking_status_transitions (from_code, to_code) VALUES
          ('new', 'contacted'), ('new', 'awaiting_payment'),
          ('awaiting_payment', 'paid'), ('paid', 'contacted'),
          ('contacted', 'confirmed'), ('confirmed', 'scheduled'),
          ('scheduled', 'completed'), ('scheduled', 'published'), ('completed', 'published'),
          ('new', 'cancelled'), ('awaiting_payment', 'cancelled'), ('paid', 'cancelled'),
          ('contacted', 'cancelled'), ('confirmed', 'cancelled'), ('scheduled', 'cancelled')
        ON CONFLICT DO NOTHING
        SQL);
}

function insertBooking(array $overrides = []): int
{
    static $n = 0;
    $n++;
    ensureBookingWorkflow();
    $customer = DB::table('customers')->insertGetId(['name' => 'سامي', 'email' => "sami{$n}@example.com", 'phone' => '+970599123456']);

    return DB::table('bookings')->insertGetId([
        'service_id' => insertService(), 'customer_id' => $customer, 'preferred_contact' => 'whatsapp',
        'details' => 'تفاصيل', 'price_amount' => 30000, 'currency_code' => 'USD',
        'access_token_hash' => hash('sha256', "booking-{$n}"), 'consent_at' => now(), ...$overrides,
    ]);
}

function insertPayment(int $booking, array $overrides = []): int
{
    static $n = 0;
    $n++;
    ensure('payment_gateways', ['code' => 'fake', 'name_ar' => 'بوابة تجريبية (للاختبار فقط)']);

    return DB::table('payments')->insertGetId([
        'booking_id' => $booking, 'gateway_code' => 'fake', 'attempt_no' => $n, 'gateway_order_id' => "ORD-{$n}",
        'amount' => 30000, 'currency_code' => 'USD', 'status' => 'captured', 'idempotency_key' => "key-{$n}",
        'paid_at' => now(), ...$overrides,
    ]);
}

/** A configuration that passes every check; each test spoils exactly one thing. */
function productionConfig(array $overrides = []): void
{
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'mail.default' => 'smtp',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'strict',
        'session.cookie' => '__Host-raqam_session',
        'session.path' => '/',
        'session.domain' => null,
        'session.lifetime' => 30,
        'raqam.auth.code_key' => 'a-code-key',
        'raqam.tracking_key' => 'a-tracking-key',
        'raqam.admin_path' => 'some-path',
        'raqam.search.key' => 'a-scoped-search-key',
        'raqam.search.prefix' => 'raqam_',
        ...$overrides,
    ]);
}

/**
 * Stands in for the leaked-password range service. Passwords in $leaked are "found in a breach";
 * with $reachable = false the service does not answer.
 *
 * @param  list<string>  $leaked
 */
function fakePwned(array $leaked = [], bool $reachable = true): void
{
    Http::swap(new Factory);   // a later call replaces an earlier stand-in
    Http::fake(['api.pwnedpasswords.com/*' => function ($request) use ($leaked, $reachable) {
        if (! $reachable) {
            return Http::response('', 503);
        }

        $prefix = substr($request->url(), -5);
        $lines = ['0000000000000000000000000000000000A:0'];   // padding, as the real service adds
        foreach ($leaked as $password) {
            $hash = strtoupper(sha1($password));
            if (str_starts_with($hash, $prefix)) {
                $lines[] = substr($hash, 5).':4242';
            }
        }

        return Http::response(implode("\n", $lines));
    }]);
}
