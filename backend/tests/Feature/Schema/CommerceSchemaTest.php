<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/08-commerce.sql, ported from database/tests/schema_tests.sql.

function ensureAdPlacements(): void
{
    DB::statement(<<<'SQL'
        INSERT INTO ad_placements (code, name_ar, desktop_width, desktop_height, mobile_width, mobile_height,
                                   is_enabled, frequency_cap_hours, lazy_load) VALUES
          ('sidebar_mpu', 'مربع جانبي',   300, 250, 300, 250, true,  NULL, true),
          ('popup',       'نافذة منبثقة', 600, 500, 320, 400, false, 24,   true)
        ON CONFLICT DO NOTHING
        SQL);
}

function insertCampaign(array $overrides = []): int
{
    $organization = DB::table('organizations')->insertGetId(['name' => 'معلن', 'slug' => 'advertiser-'.uniqid()]);

    return DB::table('ad_campaigns')->insertGetId([
        'advertiser_id' => DB::table('advertisers')->insertGetId(['organization_id' => $organization]),
        'name' => 'حملة', 'target_url' => 'https://example.com', 'status' => 'active',
        'starts_at' => DB::raw("now() - interval '1 day'"), 'ends_at' => DB::raw("now() + interval '1 day'"), ...$overrides,
    ]);
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

function moveBooking(int $booking, string $status): void
{
    DB::table('booking_status_history')->insert(['booking_id' => $booking, 'status_code' => $status]);
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

function insertInvoice(?int $taxRate = 1600): int
{
    $booking = insertBooking();
    $invoice = DB::table('invoices')->insertGetId([
        'booking_id' => $booking, 'payment_id' => insertPayment($booking), 'billed_name' => 'سامي',
        'billed_email' => 'sami@example.com', 'currency_code' => 'USD', 'tax_rate_bp' => $taxRate,
    ]);
    DB::table('invoice_lines')->insert(['invoice_id' => $invoice, 'line_no' => 1, 'description' => 'حلقة بودكاست', 'quantity' => 1, 'unit_amount' => 30000]);

    return $invoice;
}

function localYear(): int
{
    return DB::selectOne('select local_year() as y')->y;
}

describe('ads', function () {
    test('live_ad_campaigns lists only running, active campaigns on enabled placements', function () {
        ensureAdPlacements();
        $live = insertCampaign(['name' => 'live']);
        $draft = insertCampaign(['name' => 'draft', 'status' => 'draft']);
        $ended = insertCampaign(['name' => 'ended', 'starts_at' => DB::raw("now() - interval '2 days'"), 'ends_at' => DB::raw('now()')]);
        $future = insertCampaign(['name' => 'future', 'starts_at' => DB::raw("now() + interval '1 day'"), 'ends_at' => DB::raw("now() + interval '2 days'")]);
        $popup = insertCampaign(['name' => 'popup']);
        foreach ([$live, $draft, $ended, $future] as $campaign) {
            DB::table('ad_campaign_placements')->insert(['campaign_id' => $campaign, 'placement_code' => 'sidebar_mpu']);
        }
        DB::table('ad_campaign_placements')->insert(['campaign_id' => $popup, 'placement_code' => 'popup']);   // placement disabled

        expect(DB::table('live_ad_campaigns')->pluck('name')->all())->toBe(['live']);
    });

    test('a campaign ends after it starts', function () {
        expect(rejected(fn () => insertCampaign(['starts_at' => DB::raw('now()'), 'ends_at' => DB::raw('now()')])))
            ->toThrow(QueryException::class, '"ad_campaigns_check"');
    });

    test('only the popup has a frequency cap', function () {
        ensureAdPlacements();

        expect(rejected(fn () => DB::table('ad_placements')->where('code', 'sidebar_mpu')->update(['frequency_cap_hours' => 24])))
            ->toThrow(QueryException::class, '"ad_placements_check"');
    });

    test('a house ad needs at least one image', function () {
        ensureAdPlacements();

        expect(rejected(fn () => DB::table('house_ads')->insert(['placement_code' => 'sidebar_mpu'])))
            ->toThrow(QueryException::class, '"house_ads_check"');
    });

    test('a house ad links to a path or a web address', function () {
        ensureAdPlacements();

        expect(rejected(fn () => DB::table('house_ads')->insert([
            'placement_code' => 'sidebar_mpu', 'desktop_media_id' => insertMedia(), 'target_path' => 'javascript:alert(1)',
        ])))->toThrow(QueryException::class, '"house_ads_target_path_check"');
    });
});

describe('services', function () {
    test('a service priced on request has no price', function () {
        expect(rejected(fn () => insertService(['price_mode' => 'on_request'])))
            ->toThrow(QueryException::class, '"services_check"');
    });

    test('a price comes with its currency', function () {
        expect(rejected(fn () => insertService(['currency_code' => null])))
            ->toThrow(QueryException::class, '"services_check1"');
    });

    test('a sale price comes with its end date', function () {
        expect(rejected(fn () => insertService(['sale_price_amount' => 25000])))
            ->toThrow(QueryException::class, '"services_check2"');
    });

    test('a sale price is lower than the price', function () {
        expect(rejected(fn () => insertService(['sale_price_amount' => 32000, 'sale_ends_at' => now()->addWeek()])))
            ->toThrow(QueryException::class, '"services_check3"');
    });
});

describe('bookings', function () {
    test('booking references are generated per year without gaps', function () {
        $first = insertBooking();
        $second = insertBooking();
        $year = localYear();

        expect(DB::table('bookings')->whereIn('id', [$first, $second])->orderBy('id')->pluck('reference')->all())
            ->toBe(["RQ-{$year}-00001", "RQ-{$year}-00002"]);
    });

    test('a new booking always starts as new, with its first history row', function () {
        $booking = insertBooking(['status_code' => 'paid']);

        expect(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('new')
            ->and(DB::table('booking_status_history')->where('booking_id', $booking)->get(['status_code', 'note'])->map(fn ($r) => (array) $r)->all())
            ->toBe([['status_code' => 'new', 'note' => 'created']]);
    });

    test('a booking status cannot be updated directly', function () {
        $booking = insertBooking();

        expect(rejected(fn () => DB::table('bookings')->where('id', $booking)->update(['status_code' => 'confirmed'])))
            ->toThrow(QueryException::class, 'Change booking status by inserting into booking_status_history');
    });

    test('an illegal transition is refused', function () {
        $booking = insertBooking();

        expect(rejected(fn () => moveBooking($booking, 'published')))
            ->toThrow(QueryException::class, "Booking {$booking}: transition new -> published is not allowed");
    });

    test('a legal transition updates the booking', function () {
        $booking = insertBooking();
        moveBooking($booking, 'contacted');
        moveBooking($booking, 'confirmed');

        expect(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('confirmed')
            ->and(DB::table('booking_status_history')->where('booking_id', $booking)->count())->toBe(3);
    });

    test('a booking price comes with its currency', function () {
        expect(rejected(fn () => insertBooking(['currency_code' => null])))
            ->toThrow(QueryException::class, '"bookings_check"');
    });
});

describe('payments and refunds', function () {
    test('a booking is paid successfully only once', function () {
        $booking = insertBooking();
        insertPayment($booking);

        expect(rejected(fn () => insertPayment($booking)))
            ->toThrow(QueryException::class, '"payments_one_success_per_booking_uq"');
    });

    test('a failed attempt does not block a new payment', function () {
        $booking = insertBooking();
        insertPayment($booking, ['status' => 'failed', 'paid_at' => null, 'failure_reason' => 'declined']);

        expect(insertPayment($booking))->toBeInt();
    });

    test('an idempotency key is used once', function () {
        $booking = insertBooking();
        insertPayment($booking, ['idempotency_key' => 'bk1-a1']);

        expect(rejected(fn () => insertPayment(insertBooking(), ['idempotency_key' => 'bk1-a1'])))
            ->toThrow(QueryException::class, '"payments_idempotency_key_key"');
    });

    test('a captured payment records when it was paid', function () {
        expect(rejected(fn () => insertPayment(insertBooking(), ['paid_at' => null])))
            ->toThrow(QueryException::class, '"payments_check"');
    });

    test('a failed payment records why', function () {
        expect(rejected(fn () => insertPayment(insertBooking(), ['status' => 'failed', 'paid_at' => null])))
            ->toThrow(QueryException::class, '"payments_check1"');
    });

    test('refunds can never exceed the payment', function () {
        $payment = insertPayment(insertBooking());
        DB::table('refunds')->insert(['payment_id' => $payment, 'amount' => 10000, 'status' => 'succeeded']);

        expect(rejected(fn () => DB::table('refunds')->insert(['payment_id' => $payment, 'amount' => 25000])))
            ->toThrow(QueryException::class, 'Refunds (35000) would exceed the payment amount (30000)');
    });

    test('failed refunds do not count towards the limit', function () {
        $payment = insertPayment(insertBooking());
        DB::table('refunds')->insert(['payment_id' => $payment, 'amount' => 25000, 'status' => 'failed']);
        DB::table('refunds')->insert(['payment_id' => $payment, 'amount' => 30000, 'status' => 'succeeded']);

        expect(DB::table('refunds')->where('payment_id', $payment)->count())->toBe(2);
    });
});

describe('invoices', function () {
    test('invoice numbers are generated per year', function () {
        $invoice = insertInvoice();

        expect(DB::table('invoices')->where('id', $invoice)->value('number'))->toBe('INV-'.localYear().'-00001');
    });

    test('invoice_totals computes subtotal, VAT and total', function (?int $taxRate, string $expected) {
        $invoice = insertInvoice($taxRate);
        $totals = DB::table('invoice_totals')->where('invoice_id', $invoice)->first();

        expect("{$totals->subtotal}/{$totals->tax_amount}/{$totals->total_amount}")->toBe($expected);
    })->with([
        '16 % VAT' => [1600, '30000/4800/34800'],
        'no VAT' => [null, '30000/0/30000'],
    ]);

    test('billed data cannot change, not even while the PDF is attached', function () {
        $invoice = insertInvoice();
        $pdf = insertMedia(['mime_type' => 'application/pdf', 'alt_text' => null, 'width' => null, 'height' => null, 'visibility' => 'private']);

        expect(rejected(fn () => DB::table('invoices')->where('id', $invoice)->update(['billed_name' => 'غيره'])))
            ->toThrow(QueryException::class, 'Issued invoices are immutable (only the PDF can be attached once)')
            ->and(rejected(fn () => DB::table('invoices')->where('id', $invoice)->update(['pdf_media_id' => $pdf, 'billed_name' => 'غيره'])))
            ->toThrow(QueryException::class, 'Issued invoices are immutable (only the PDF can be attached once)');
    });

    test('the PDF is attached exactly once', function () {
        $invoice = insertInvoice();
        $pdf = fn () => insertMedia(['mime_type' => 'application/pdf', 'alt_text' => null, 'width' => null, 'height' => null, 'visibility' => 'private']);
        DB::table('invoices')->where('id', $invoice)->update(['pdf_media_id' => $pdf()]);

        expect(rejected(fn () => DB::table('invoices')->where('id', $invoice)->update(['pdf_media_id' => $pdf()])))
            ->toThrow(QueryException::class, 'Issued invoices are immutable (only the PDF can be attached once)');
    });

    test('an invoice cannot be deleted', function () {
        $invoice = insertInvoice();

        expect(rejected(fn () => DB::table('invoices')->delete($invoice)))
            ->toThrow(QueryException::class, 'invoices rows cannot be deleted');
    });

    test('invoice lines cannot change or be deleted', function () {
        $invoice = insertInvoice();
        $lines = fn () => DB::table('invoice_lines')->where('invoice_id', $invoice);

        expect(rejected(fn () => $lines()->update(['unit_amount' => 1])))
            ->toThrow(QueryException::class, 'Invoice lines cannot be changed')
            ->and(rejected(fn () => $lines()->delete()))
            ->toThrow(QueryException::class, 'invoice_lines rows cannot be deleted');
    });
});
