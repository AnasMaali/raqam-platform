<?php

use App\Booking\BookingLink;
use App\Booking\BookingStatuses;
use App\Models\User;
use App\Payments\CheckoutRequest;
use App\Payments\GatewayNotification;
use App\Payments\GatewayPayment;
use App\Payments\GatewayRefund;
use App\Payments\Gateways;
use App\Payments\Gateways\FakeGateway;
use App\Payments\PaymentGateway;
use App\Payments\PaymentRefused;
use App\Payments\Payments;
use App\Payments\Refunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const PAY_SITE_TOKEN = 'pay-site-token';

beforeEach(function () {
    config(['mail.default' => 'array', 'raqam.api.site_token' => PAY_SITE_TOKEN, 'raqam.site_url' => 'https://raqam.example',
        'raqam.payments.fake_secret' => 'fake-gateway-secret', 'queue.default' => 'sync']);
    Cache::flush();
    Storage::fake('media_local_public');
    Storage::fake('media_local_private');
    ensureBookingWorkflow();
    DB::table('feature_flags')->insertOrIgnore(['key' => 'booking', 'description_ar' => 'نظام الحجز الإلكتروني', 'is_enabled' => false]);
    DB::table('feature_flags')->insertOrIgnore(['key' => 'online_payments', 'description_ar' => 'الدفع الإلكتروني', 'is_enabled' => false, 'depends_on' => 'booking']);
    ensure('payment_gateways', ['code' => 'fake', 'name_ar' => 'بوابة تجريبية (للاختبار فقط)']);
    DB::table('payment_gateways')->where('code', 'fake')->update(['is_active' => true]);
    $this->admin = staffAdmin('admin@raqam.example');
});

function paymentsOn(): void
{
    DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => true]);
    DB::table('feature_flags')->where('key', 'online_payments')->update(['is_enabled' => true]);
}

/** A booking that waits for payment; returns [booking id, its private token]. */
function bookingAwaitingPayment(array $overrides = []): array
{
    $token = BookingLink::newToken();
    $id = insertBooking(['access_token_hash' => BookingLink::hash($token), ...$overrides]);
    app(BookingStatuses::class)->move($id, 'awaiting_payment');

    return [$id, $token];
}

function payHeaders(): array
{
    return ['X-Raqam-Site-Token' => PAY_SITE_TOKEN, 'X-Raqam-Visitor-Ip' => '203.0.113.7'];
}

function startPayment(string $token, array $extra = [])
{
    return test()->flushHeaders()->withHeaders(payHeaders())->postJson('/api/v1/bookings/pay', ['token' => $token, ...$extra]);
}

function returnFromBank(string $order)
{
    return test()->flushHeaders()->withHeaders(payHeaders())->postJson('/api/v1/payments/return', ['order_id' => $order]);
}

function bankNotifies(string $eventId, string $order, ?string $secret = null)
{
    [$body, $headers] = FakeGateway::signedNotification($eventId, $order, secret: $secret);

    return test()->flushHeaders()->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], array_merge(
        ['CONTENT_TYPE' => 'application/json'], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()), $body);
}

/** The database's clock decides every age here, so rows are made older instead of moving PHP's clock. */
function ageRows(string $table, string $interval, ?string $order = null): void
{
    $q = DB::table($table);
    if ($order !== null) {
        $q->whereIn('id', DB::table('payments')->where('gateway_order_id', $order)->select('id'));
    }
    $q->update(['created_at' => DB::raw("created_at - interval '{$interval}'")]);
}

function lastOrder(): string
{
    return (string) DB::table('payments')->orderByDesc('id')->value('gateway_order_id');
}

// --- Switched off (16.1, 16.13) -------------------------------------------------------------------------------------

test('every payment route is a 404 unless both booking and online payments are on', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    DB::table('feature_flags')->where('key', 'online_payments')->update(['is_enabled' => false]);

    foreach ([false, true] as $bookingOn) {
        DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => $bookingOn]);
        startPayment($token)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        returnFromBank('Pwhatever')->assertNotFound();
        bankNotifies('ev-1', 'Pwhatever')->assertNotFound();
    }
    expect(DB::table('payments')->count())->toBe(0)->and(DB::table('payment_events')->count())->toBe(0);
    expect(fn () => app(Payments::class)->start($token))->toThrow(PaymentRefused::class, 'الدفع الإلكتروني غير متاح.');
    $this->artisan('payments:reconcile')->expectsOutput('online payments are off')->assertSuccessful();
});

// --- Starting (16.3, 16.8) ------------------------------------------------------------------------------------------

test('a card detail is never accepted: the request is refused and nothing is opened', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    foreach (['card_number' => '4111111111111111', 'cvv' => '123', 'pan' => '4111', 'expiry' => '12/30'] as $field => $value) {
        startPayment($token, [$field => $value])->assertStatus(422)->assertJsonPath('error.code', 'card_data');
    }
    expect(DB::table('payments')->count())->toBe(0)
        ->and(json_encode(DB::table('payment_events')->get()).json_encode(DB::table('email_log')->get()))->not->toContain('4111');
});

test('only a booking waiting for payment, with a fixed price, can be paid', function () {
    paymentsOn();
    $newToken = BookingLink::newToken();
    insertBooking(['access_token_hash' => BookingLink::hash($newToken)]);
    startPayment($newToken)->assertNotFound()->assertJsonPath('error.code', 'not_payable');
    startPayment(BookingLink::newToken())->assertNotFound();
    startPayment('not-a-token')->assertNotFound();

    $from = insertService(['price_mode' => 'from']);
    [, $fromToken] = bookingAwaitingPayment(['service_id' => $from]);
    startPayment($fromToken)->assertNotFound();
    [, $noPrice] = bookingAwaitingPayment(['price_amount' => null, 'currency_code' => null]);
    startPayment($noPrice)->assertNotFound();
    expect(DB::table('payments')->count())->toBe(0);
});

test('starting opens one attempt with its own key; starting again soon hands back the same one; later, a new one', function () {
    paymentsOn();
    [$booking, $token] = bookingAwaitingPayment();
    $first = startPayment($token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.redirect_url');
    $p = DB::table('payments')->first();
    expect($first)->toBe('https://pay.fake-gateway.test/checkout/'.$p->gateway_order_id)
        ->and($p->gateway_order_id)->toMatch('/^P[a-z0-9]{24}$/')->and($p->status)->toBe('pending')->and($p->attempt_no)->toBe(1)
        ->and($p->amount)->toBe(12345)->and(trim($p->currency_code))->toBe('USD')->and($p->idempotency_key)->toMatch('/^[0-9a-f-]{36}$/');

    expect(startPayment($token)->json('data.redirect_url'))->toBe($first);
    expect(DB::table('payments')->count())->toBe(1)->and(FakeGateway::order($p->gateway_order_id)['pages'])->toBe(2);

    ageRows('payments', '11 minutes');
    $second = startPayment($token)->json('data.redirect_url');
    expect($second)->not->toBe($first)->and(DB::table('payments')->where('booking_id', $booking)->pluck('attempt_no')->all())->toBe([1, 2]);
});

// --- Paid only when the bank says so (16.5) --------------------------------------------------------------------------

test('coming back from the bank proves nothing; paid only when the bank says so, then the booking, invoice and receipt follow', function () {
    paymentsOn();
    [$booking, $token] = bookingAwaitingPayment();
    startPayment($token);
    $order = lastOrder();

    returnFromBank($order)->assertOk()->assertJsonPath('data.status', 'pending');
    expect(DB::table('payments')->value('status'))->toBe('pending')->and(DB::table('bookings')->value('status_code'))->toBe('awaiting_payment');

    $tx = FakeGateway::pay($order);
    returnFromBank($order)->assertOk()->assertJson(['data' => ['status' => 'paid', 'reference' => DB::table('bookings')->value('reference')]]);

    $p = DB::table('payments')->first();
    expect($p->status)->toBe('captured')->and($p->gateway_transaction_id)->toBe($tx)->and($p->paid_at)->not->toBeNull();
    expect(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('paid');

    $invoice = DB::table('invoices')->first();
    expect($invoice->number)->toBe('INV-'.now('Asia/Hebron')->year.'-00001')->and($invoice->payment_id)->toBe($p->id)
        ->and($invoice->billed_email)->toStartWith('sami')->and($invoice->tax_rate_bp)->toBeNull()->and($invoice->pdf_media_id)->not->toBeNull();
    expect(DB::table('invoice_totals')->first(['subtotal', 'total_amount']))->toEqual((object) ['subtotal' => 12345, 'total_amount' => 12345]);
    expect(DB::table('media_assets')->where('id', $invoice->pdf_media_id)->first(['visibility', 'mime_type']))
        ->toEqual((object) ['visibility' => 'private', 'mime_type' => 'application/pdf']);

    $mail = sentEmailsTo($invoice->billed_email);
    $receipt = collect($mail)->first(fn ($m) => str_contains($m->getSubject(), 'إيصال دفع'));
    expect($receipt)->not->toBeNull()->and($receipt->getAttachments())->toHaveCount(1)
        ->and($receipt->getAttachments()[0]->getFilename())->toBe($invoice->number.'.pdf')
        ->and(substr($receipt->getAttachments()[0]->getBody(), 0, 5))->toBe('%PDF-');
});

test('a bank answer for another amount or currency is not a payment, and the admins are told', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    FakeGateway::pay(lastOrder(), amount: 999);

    returnFromBank(lastOrder())->assertJsonPath('data.status', 'not_paid');
    expect(DB::table('payments')->first(['status', 'failure_reason']))->toEqual((object) ['status' => 'failed', 'failure_reason' => 'المبلغ أو العملة لدى البنك لا يطابقان المطلوب.'])
        ->and(DB::table('bookings')->value('status_code'))->toBe('awaiting_payment')->and(DB::table('invoices')->count())->toBe(0);
    expect(collect(sentEmailsTo('admin@raqam.example'))->map->getSubject()->implode('|'))->toContain('دفعة لا يطابق مبلغها');
});

test('a declined payment is recorded as failed with the bank\'s reason; the customer may try again', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    FakeGateway::decline(lastOrder());
    returnFromBank(lastOrder())->assertJsonPath('data.status', 'not_paid');
    expect(DB::table('payments')->first(['status', 'failure_reason']))->toEqual((object) ['status' => 'failed', 'failure_reason' => 'رفض البنك البطاقة']);

    startPayment($token)->assertOk();
    expect(DB::table('payments')->count())->toBe(2);
});

// --- Notifications (16.6) -------------------------------------------------------------------------------------------

test('a signed notification is kept, acted on once, and its content is not believed', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    $order = lastOrder();

    bankNotifies('ev-1', $order)->assertOk();   // says "paid", but the bank has no payment yet
    $asked = Cache::get('fake-gateway:status-calls');
    bankNotifies('ev-1', $order)->assertOk();   // sent again: not acted on a second time, the bank is not even asked
    expect(Cache::get('fake-gateway:status-calls'))->toBe($asked);
    expect(DB::table('payments')->value('status'))->toBe('pending')
        ->and(DB::table('payment_events')->first(['signature_valid', 'event_type']))->toEqual((object) ['signature_valid' => true, 'event_type' => 'payment.updated'])
        ->and(DB::table('payment_events')->value('processed_at'))->not->toBeNull();

    FakeGateway::pay($order);
    bankNotifies('ev-2', $order)->assertOk();
    bankNotifies('ev-2', $order)->assertOk();   // the bank sends it again
    expect(DB::table('payments')->value('status'))->toBe('captured')->and(DB::table('payment_events')->count())->toBe(2)
        ->and(DB::table('invoices')->count())->toBe(1)->and(DB::table('payment_events')->where('gateway_event_id', 'ev-2')->value('payment_id'))->toBe(DB::table('payments')->value('id'));
});

test('a notification with a wrong signature is kept apart and changes nothing, and cannot take a real event\'s id', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    $order = lastOrder();
    FakeGateway::pay($order);

    bankNotifies('ev-9', $order, secret: 'forged')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
    $forged = DB::table('payment_events')->first();
    expect($forged->signature_valid)->toBeFalse()->and($forged->gateway_event_id)->toStartWith('invalid-')->and($forged->processed_at)->toBeNull()
        ->and(DB::table('payments')->value('status'))->toBe('pending');

    bankNotifies('ev-9', $order)->assertOk();   // the real one, with the id the forger used
    expect(DB::table('payments')->value('status'))->toBe('captured');
});

// --- A second payment of a paid booking (decision 2026-10-24) -------------------------------------------------------

test('a second successful payment is kept as a duplicate, the admins are told, and nothing is refunded by itself', function () {
    paymentsOn();
    [$booking, $token] = bookingAwaitingPayment();
    startPayment($token);
    $first = lastOrder();
    ageRows('payments', '11 minutes');
    startPayment($token);
    $second = lastOrder();
    FakeGateway::pay($first);
    FakeGateway::pay($second);

    returnFromBank($first)->assertJsonPath('data.status', 'paid');
    returnFromBank($second)->assertJsonPath('data.status', 'paid');

    expect(DB::table('payments')->orderBy('id')->pluck('status')->all())->toBe(['captured', 'duplicate'])
        ->and(DB::table('payments')->where('gateway_order_id', $second)->value('paid_at'))->not->toBeNull()
        ->and(DB::table('invoices')->count())->toBe(1)->and(DB::table('refunds')->count())->toBe(0)
        ->and(FakeGateway::order($second)['refunded'])->toBe(0)
        ->and(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('paid');
    expect(collect(sentEmailsTo('admin@raqam.example'))->map->getSubject()->implode('|'))->toContain('دفعة مكررة');
    startPayment($token)->assertNotFound()->assertJsonPath('error.code', 'not_payable');   // the booking is paid now
});

test('a booking with a successful payment is never opened again, even if it waits for payment', function () {
    paymentsOn();
    [$booking, $token] = bookingAwaitingPayment();
    insertPayment($booking);   // captured, the booking left waiting behind the services' back
    startPayment($token)->assertStatus(409)->assertJsonPath('error.code', 'already_paid');
    expect(DB::table('payments')->count())->toBe(1);
});

// --- Reconciliation (16.7) ------------------------------------------------------------------------------------------

test('every 15 minutes the bank is asked about attempts pending for more than 10 minutes; a day without payment closes one', function () {
    paymentsOn();
    [, $paidToken] = bookingAwaitingPayment();
    startPayment($paidToken);
    $paid = lastOrder();
    [, $freshToken] = bookingAwaitingPayment();
    FakeGateway::pay($paid);
    ageRows('payments', '11 minutes', $paid);
    startPayment($freshToken);   // younger than 10 minutes when the task runs
    $fresh = lastOrder();
    FakeGateway::pay($fresh);

    $this->artisan('payments:reconcile')->expectsOutput('payments settled: 1')->assertSuccessful();
    expect(DB::table('payments')->where('gateway_order_id', $paid)->value('status'))->toBe('captured')
        ->and(DB::table('payments')->where('gateway_order_id', $fresh)->value('status'))->toBe('pending');

    [, $abandoned] = bookingAwaitingPayment();
    startPayment($abandoned);
    $left = lastOrder();
    ageRows('payments', '25 hours', $left);
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(DB::table('payments')->where('gateway_order_id', $left)->value('status'))->toBe('cancelled');
});

// --- Refunds (16.10) ------------------------------------------------------------------------------------------------

function capturedPayment(): int
{
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    FakeGateway::pay(lastOrder());
    returnFromBank(lastOrder());

    return (int) DB::table('payments')->where('gateway_order_id', lastOrder())->value('id');
}

test('a payment is refunded in parts, never beyond what was paid, and the customer is told', function () {
    paymentsOn();
    $payment = capturedPayment();
    $refunds = app(Refunds::class);

    $refunds->refund($payment, 2345, 'خصم متفق عليه', $this->admin ? User::find($this->admin) : null);
    expect(DB::table('payments')->where('id', $payment)->value('status'))->toBe('partially_refunded')
        ->and(DB::table('refunds')->first(['amount', 'status', 'created_by']))->toEqual((object) ['amount' => 2345, 'status' => 'succeeded', 'created_by' => $this->admin]);
    expect(fn () => $refunds->refund($payment, 10001, null, null))->toThrow(PaymentRefused::class, 'أكبر من المتبقي');
    expect(fn () => $refunds->refund($payment, 0, null, null))->toThrow(PaymentRefused::class);

    $refunds->refund($payment, 10000, null, null);
    expect(DB::table('payments')->where('id', $payment)->value('status'))->toBe('refunded')
        ->and(FakeGateway::order(lastOrder())['refunded'])->toBe(12345);
    expect(fn () => $refunds->refund($payment, 1, null, null))->toThrow(PaymentRefused::class, 'هذه الدفعة لا تُسترجع.');
    $customer = DB::table('invoices')->value('billed_email');
    expect(collect(sentEmailsTo($customer))->filter(fn ($m) => str_contains($m->getSubject(), 'استرجاع'))->count())->toBe(2);
});

test('the admin may refund a duplicate, which stays marked as one', function () {
    paymentsOn();
    capturedPayment();
    $dup = DB::table('payments')->insertGetId(['booking_id' => DB::table('payments')->value('booking_id'), 'gateway_code' => 'fake', 'attempt_no' => 9,
        'gateway_order_id' => 'Pdup', 'amount' => 12345, 'currency_code' => 'USD', 'status' => 'duplicate', 'idempotency_key' => 'k-dup', 'paid_at' => now()]);
    Cache::put('fake-gateway:Pdup', ['key' => 'k-dup', 'amount' => 12345, 'currency' => 'USD', 'state' => 'paid', 'transaction' => 'TX', 'refunded' => 0, 'refunds' => [], 'pages' => 1], 3600);

    app(Refunds::class)->refund($dup, 12345, 'دفع مرتين', null);
    expect(DB::table('payments')->where('id', $dup)->value('status'))->toBe('duplicate')
        ->and(DB::table('refunds')->where('payment_id', $dup)->value('status'))->toBe('succeeded');
});

test('a refund the bank refuses is recorded as failed and the admins are told; an interrupted one is finished once', function () {
    paymentsOn();
    $payment = capturedPayment();
    $order = lastOrder();
    $state = FakeGateway::order($order);
    Cache::put('fake-gateway:'.$order, [...$state, 'state' => 'failed'], 3600);   // the bank will refuse
    expect(fn () => app(Refunds::class)->refund($payment, 2345, null, null))->toThrow(PaymentRefused::class, 'رفض البنك الاسترجاع');
    expect(DB::table('refunds')->value('status'))->toBe('failed')->and(DB::table('payments')->where('id', $payment)->value('status'))->toBe('captured');
    expect(collect(sentEmailsTo('admin@raqam.example'))->map->getSubject()->implode('|'))->toContain('رفض البنك استرجاعاً');

    Cache::put('fake-gateway:'.$order, $state, 3600);
    $pending = DB::table('refunds')->insertGetId(['payment_id' => $payment, 'amount' => 2345]);
    // The bank refunded it, but the answer was lost before it was written down: asking again with the same key refunds nothing more.
    app(Gateways::class)->get('fake')->refund($order, 2345, 'USD', 'refund-'.$pending);
    ageRows('refunds', '11 minutes');
    $this->artisan('payments:reconcile')->assertSuccessful();
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(DB::table('refunds')->where('id', $pending)->value('status'))->toBe('succeeded')
        ->and(FakeGateway::order($order)['refunded'])->toBe(2345);
});

// --- The test gateway ---------------------------------------------------------------------------------------------

test('the test gateway never works in production, and an unconnected bank cannot be used', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(Gateways::class)->active())->toThrow(PaymentRefused::class, 'البوابة التجريبية لا تعمل في الإنتاج.');
    app()->detectEnvironment(fn () => 'testing');

    DB::table('payment_gateways')->update(['is_active' => false]);
    ensure('payment_gateways', ['code' => 'bank_of_palestine', 'name_ar' => 'بنك فلسطين']);
    DB::table('payment_gateways')->where('code', 'bank_of_palestine')->update(['is_active' => true]);
    expect(fn () => app(Gateways::class)->active())->toThrow(PaymentRefused::class, 'بوابة الدفع هذه غير مربوطة بعد.');
    DB::table('payment_gateways')->update(['is_active' => false]);
    expect(fn () => app(Gateways::class)->active())->toThrow(PaymentRefused::class, 'لا توجد بوابة دفع مفعّلة.');
});

// --- Review findings (phase 10 security review) --------------------------------------------------------------------

test('F1: a notification larger than 64 KB is refused before anything is kept', function () {
    paymentsOn();
    $body = json_encode(['id' => 'ev-big', 'type' => 'payment.updated', 'order_id' => 'Px', 'pad' => str_repeat('a', 70_000)]);
    test()->flushHeaders()->call('POST', '/api/v1/payments/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', $body, 'fake-gateway-secret')], $body)->assertStatus(413)->assertJsonPath('error.code', 'too_large');
    expect(DB::table('payment_events')->count())->toBe(0);
});

test('F2: money the bank took for an attempt already closed here is never lost: the admins are told', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    $order = lastOrder();
    ageRows('payments', '25 hours', $order);
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(DB::table('payments')->value('status'))->toBe('cancelled');

    FakeGateway::pay($order);   // the customer finished on the bank's page after all
    bankNotifies('ev-late', $order)->assertOk();
    expect(DB::table('payments')->value('status'))->toBe('cancelled')   // a closed attempt is not reopened
        ->and(collect(sentEmailsTo('admin@raqam.example'))->map->getSubject()->implode('|'))->toContain('دفعة وصلت لمحاولة مغلقة');
});

test('F3: a bank that does not answer is a clear 503 when starting, and "pending" when coming back; nothing breaks', function () {
    paymentsOn();
    [, $token] = bookingAwaitingPayment();
    startPayment($token);
    $order = lastOrder();
    app()->bind(FakeGateway::class, fn () => new class implements PaymentGateway
    {
        public function code(): string
        {
            return 'fake';
        }

        public function checkout(CheckoutRequest $request): string
        {
            throw new RuntimeException('connection refused');
        }

        public function status(string $orderId): GatewayPayment
        {
            throw new RuntimeException('connection refused');
        }

        public function notification(Request $request): ?GatewayNotification
        {
            return null;
        }

        public function refund(string $orderId, int $amount, string $currency, string $idempotencyKey): GatewayRefund
        {
            throw new RuntimeException('down');
        }
    });

    startPayment($token)->assertStatus(503)->assertJsonPath('error.code', 'gateway_failed')->assertJsonMissingPath('error.details');
    returnFromBank($order)->assertOk()->assertJsonPath('data.status', 'pending');
    expect(DB::table('payments')->value('status'))->toBe('pending');
});
