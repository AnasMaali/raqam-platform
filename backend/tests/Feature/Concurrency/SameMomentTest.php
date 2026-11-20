<?php

use App\Booking\BookingLink;
use App\Booking\BookingStatuses;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Models\Post;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Invoices;
use App\Payments\PaymentRefused;
use App\Payments\Payments;
use App\Payments\Refunds;
use Illuminate\Support\Facades\DB;

/*
 * Two people, or two processes, doing the same thing to the same row at the same moment: two refunds of one payment,
 * the bank's answer arriving twice, two status moves of one booking, two editors saving one article, invoices
 * numbered side by side. Each case runs in real child processes with their own connections, held at a gate (an
 * advisory lock this process keeps) until every one of them is waiting, then let go together. The row locks must turn
 * that into one after the other: nothing counted twice, nothing lost, every refusal a clean one.
 *
 * Needs committed rows seen by other connections, so this file does not use RefreshDatabase; it rebuilds the test
 * database before and after each test instead (as BookingAttachmentLockTest does).
 */

beforeEach(fn () => $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful());
afterEach(fn () => $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful());

const SAME_MOMENT_GATE = 4_812_026;

/**
 * Runs $work($i) in $n child processes that all start at once; returns what each returned, or the refusal it met.
 *
 * A child never closes, or even destroys, this process's database connection (they share its socket): it opens its
 * own, and ends with SIGKILL so no destructor or shutdown handler runs. The tests' cache is an array and their queue
 * runs at once (phpunit.xml), so each child keeps a copy of what this process had cached (the fake bank's orders).
 *
 * @return list<array{ok: bool, value: mixed, error: ?string, class: ?string}>
 */
function atTheSameMoment(int $n, Closure $work): array
{
    DB::select('SELECT pg_advisory_lock(?)', [SAME_MOMENT_GATE]);
    $children = [];
    for ($i = 0; $i < $n; $i++) {
        $file = tempnam(sys_get_temp_dir(), 'raqam-moment-');
        $pid = pcntl_fork();
        if ($pid === 0) {
            $result = ['ok' => false, 'value' => null, 'error' => null, 'class' => null];
            try {
                config(['database.connections.moment' => config('database.connections.'.config('database.default'))]);
                DB::setDefaultConnection('moment');
                DB::select('SELECT pg_advisory_lock_shared(?)', [SAME_MOMENT_GATE]);   // waits at the gate
                DB::select('SELECT pg_advisory_unlock_shared(?)', [SAME_MOMENT_GATE]);
                $result['value'] = $work($i);
                $result['ok'] = true;
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
                $result['class'] = $e::class;
            }
            file_put_contents($file, serialize($result));
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = [$pid, $file];
    }

    // Open the gate only when every child is waiting at it.
    $waiting = fn () => (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND NOT granted
        AND database = (SELECT oid FROM pg_database WHERE datname = current_database())", [SAME_MOMENT_GATE])->n;   // advisory locks are per database
    $deadline = microtime(true) + 30;
    while ($waiting() < $n && microtime(true) < $deadline) {
        usleep(20_000);
    }
    expect($waiting())->toBe($n, 'every child waits at the gate');
    DB::select('SELECT pg_advisory_unlock(?)', [SAME_MOMENT_GATE]);

    $results = [];
    foreach ($children as [$pid, $file]) {
        pcntl_waitpid($pid, $status);
        $results[] = unserialize((string) file_get_contents($file)) ?: ['ok' => false, 'value' => null, 'error' => 'the child died', 'class' => null];
        @unlink($file);
    }

    return $results;
}

/** The payments switched on, the fake bank active, the workflow seeded (as PaymentsTest sets them up). */
function sameMomentPayments(): void
{
    config(['mail.default' => 'array', 'queue.default' => 'sync', 'raqam.payments.fake_secret' => 'fake-gateway-secret']);
    ensureBookingWorkflow();
    DB::table('feature_flags')->insertOrIgnore([
        ['key' => 'booking', 'description_ar' => 'نظام الحجز الإلكتروني', 'is_enabled' => false, 'depends_on' => null],
        ['key' => 'online_payments', 'description_ar' => 'الدفع الإلكتروني', 'is_enabled' => false, 'depends_on' => 'booking'],
    ]);
    DB::table('feature_flags')->where('key', 'booking')->update(['is_enabled' => true]);   // the dependency first
    DB::table('feature_flags')->where('key', 'online_payments')->update(['is_enabled' => true]);
    ensure('payment_gateways', ['code' => 'fake', 'name_ar' => 'بوابة تجريبية (للاختبار فقط)']);
    DB::table('payment_gateways')->where('code', 'fake')->update(['is_active' => true]);
}

/** A booking waiting for its payment, and its attempt opened at the fake bank: [booking, payment, order]. */
function sameMomentCheckout(): array
{
    $token = BookingLink::newToken();
    $booking = insertBooking(['access_token_hash' => BookingLink::hash($token)]);   // 12345, made up
    app(BookingStatuses::class)->move($booking, 'awaiting_payment');
    app(Payments::class)->start($token);
    $payment = DB::table('payments')->where('booking_id', $booking)->first(['id', 'gateway_order_id']);

    return [$booking, (int) $payment->id, (string) $payment->gateway_order_id];
}

test('five refunds of one payment at once: only what was paid goes back, and the rest are refused cleanly', function () {
    sameMomentPayments();
    [, $payment, $order] = sameMomentCheckout();
    FakeGateway::pay($order);
    expect(app(Payments::class)->sync($payment))->toBe('captured');

    $results = atTheSameMoment(5, fn () => app(Refunds::class)->refund($payment, 5000, 'استرجاع متزامن', null));

    expect(collect($results)->where('ok', true))->toHaveCount(2)
        ->and(collect($results)->where('ok', false)->pluck('class')->unique()->values()->all())->toBe([PaymentRefused::class])
        ->and((int) DB::table('refunds')->where('payment_id', $payment)->where('status', '<>', 'failed')->sum('amount'))->toBe(10000)
        ->and(DB::table('refunds')->where('payment_id', $payment)->count())->toBe(2);
});

test('the bank\'s answer arriving three times at once captures the payment once, moves the booking once, issues one invoice', function () {
    sameMomentPayments();
    [$booking, $payment, $order] = sameMomentCheckout();
    FakeGateway::pay($order);

    $results = atTheSameMoment(3, fn () => app(Payments::class)->sync($payment));

    expect(collect($results)->pluck('error')->filter()->all())->toBe([])
        ->and(collect($results)->pluck('value')->unique()->values()->all())->toBe(['captured'])
        ->and(DB::table('payments')->where('id', $payment)->value('status'))->toBe('captured')
        ->and(DB::table('booking_status_history')->where('booking_id', $booking)->where('status_code', 'paid')->count())->toBe(1)
        ->and(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('paid')
        ->and(DB::table('invoices')->where('payment_id', $payment)->count())->toBe(1);
});

test('five admins moving one booking to the same stage at once: one move, the customer told once', function () {
    sameMomentPayments();
    $booking = insertBooking();
    app(BookingStatuses::class)->move($booking, 'contacted');

    $results = atTheSameMoment(5, fn () => app(BookingStatuses::class)->move($booking, 'confirmed', 'تأكيد'));

    expect(collect($results)->pluck('error')->filter()->all())->toBe([])
        ->and(DB::table('bookings')->where('id', $booking)->value('status_code'))->toBe('confirmed')
        ->and(DB::table('booking_status_history')->where('booking_id', $booking)->where('status_code', 'confirmed')->whereNotNull('customer_notified_at')->count())->toBe(1);
});

test('two editors saving one article at once with the version they opened: one is saved, the other is told', function () {
    $user = User::factory()->staff()->create();
    $post = insertPost(['title' => 'العنوان الأول']);
    $opened = app(PostSaver::class)->token($post);

    $results = atTheSameMoment(2, fn (int $i) => app(PostSaver::class)->save(Post::findOrFail($post), ['title' => "عنوان المحرر {$i}"], $user->id, $opened)->title);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false)->pluck('class')->all())->toBe([PostRuleViolation::class])
        ->and(DB::table('posts')->where('id', $post)->value('title'))->toBe(collect($results)->firstWhere('ok', true)['value']);
});

test('two tabs autosaving one draft at once from the version they opened: one is kept, the other is told', function () {
    $user = User::factory()->staff()->create();
    $post = insertPost(['title' => 'مسودة أولى']);
    $opened = app(PostSaver::class)->token($post);

    $results = atTheSameMoment(2, fn (int $i) => app(PostSaver::class)->autosave(Post::findOrFail($post), ['title' => "مسودة التبويب {$i}"], $user->id, $opened)->title);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('ok', false)->pluck('class')->all())->toBe([PostRuleViolation::class])
        ->and(DB::table('posts')->where('id', $post)->value('title'))->toBe(collect($results)->firstWhere('ok', true)['value']);
});

test('eight invoices issued at once are numbered one to eight, none twice, none skipped', function () {
    sameMomentPayments();
    $payments = collect(range(1, 8))->map(fn () => insertPayment(insertBooking(), ['status' => 'captured']))->all();

    $results = atTheSameMoment(8, fn (int $i) => app(Invoices::class)->issue($payments[$i]));

    expect(collect($results)->pluck('error')->filter()->all())->toBe([])
        ->and(DB::table('invoices')->orderBy('number_seq')->pluck('number_seq')->all())->toBe(range(1, 8));
});
