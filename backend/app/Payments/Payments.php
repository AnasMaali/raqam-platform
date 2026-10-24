<?php

namespace App\Payments;

use App\Booking\BookingLink;
use App\Booking\BookingStatuses;
use App\Jobs\SendInvoice;
use App\Settings\FeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying a booking online (decision 16), switched off until `online_payments` (which needs `booking`) is on.
 *
 * The customer starts from the private status link of a booking that waits for payment, and is sent to the bank's own
 * page: no card detail ever reaches us (16.3). A payment becomes "paid" only when this server asks the bank and the bank
 * says so, for the same amount and currency (16.5); what comes back with the customer's browser is never believed.
 * Each attempt has its own idempotency key (16.8), and a pending attempt is handed back instead of opening another.
 *
 * Only a fixed price is paid online: the price fixed on the booking (15.5). "From" and "on request" stay manual.
 * A second successful payment of a paid booking is kept as `duplicate` and the admins are told; giving it back is
 * their decision (2026-10-24).
 */
final class Payments
{
    /** Field names that could only be a card detail: a request carrying one is refused, whatever else it holds. */
    private const CARD_FIELDS = '/(card|pan$|cvv|cvc|expiry|expiration|security_code)/i';

    public function __construct(private Gateways $gateways, private BookingStatuses $statuses) {}

    public static function on(): bool
    {
        return FeatureFlags::on('booking') && FeatureFlags::on('online_payments');
    }

    /**
     * @param  array<string, mixed>  $input  everything the site sent (checked for card fields)
     * @return string the bank's payment page
     *
     * @throws PaymentRefused
     */
    public function start(mixed $token, array $input = []): string
    {
        if (! self::on()) {
            throw new PaymentRefused('off', 'الدفع الإلكتروني غير متاح.');
        }
        foreach (array_keys($input) as $field) {
            if (preg_match(self::CARD_FIELDS, (string) $field) === 1) {
                throw new PaymentRefused('card_data', 'لا تُرسل بيانات البطاقة إلى الموقع أبداً؛ تُكتب في صفحة البنك فقط.');
            }
        }
        $notPayable = new PaymentRefused('not_payable', 'هذا الحجز لا يُدفع إلكترونياً الآن.');
        if (! BookingLink::wellFormed($token)) {
            throw $notPayable;
        }

        $gateway = $this->gateways->active();
        $payment = DB::transaction(function () use ($token, $gateway, $notPayable) {
            $b = DB::selectOne('SELECT b.id, b.reference, b.status_code, b.price_amount, b.currency_code, s.name_ar AS service, s.price_mode
                                FROM bookings b JOIN services s ON s.id = b.service_id
                                WHERE b.access_token_hash = ? AND b.deleted_at IS NULL FOR UPDATE OF b', [BookingLink::hash($token)]);
            if ($b === null || $b->status_code !== 'awaiting_payment' || $b->price_amount === null || $b->price_mode !== 'fixed') {
                throw $notPayable;
            }
            if (DB::table('payments')->where('booking_id', $b->id)->whereIn('status', ['authorized', 'captured'])->exists()) {
                throw new PaymentRefused('already_paid', 'هذا الحجز مدفوع.');
            }
            $pending = DB::table('payments')->where('booking_id', $b->id)->where('status', 'pending')
                ->where('created_at', '>', DB::raw("now() - make_interval(mins => {$this->setting('reuse_minutes')})"))->orderByDesc('id')->first();
            if ($pending !== null) {
                return (object) [...(array) $pending, 'reference' => $b->reference, 'service' => $b->service];
            }

            $attempt = (int) DB::table('payments')->where('booking_id', $b->id)->max('attempt_no') + 1;
            $row = [
                'booking_id' => $b->id, 'gateway_code' => $gateway->code(), 'attempt_no' => $attempt,
                // Random, so nobody can guess another booking's order and ask about it.
                'gateway_order_id' => 'P'.Str::lower(Str::random(24)), 'amount' => (int) $b->price_amount, 'currency_code' => trim($b->currency_code),
                'idempotency_key' => (string) Str::uuid(),
            ];
            $row['id'] = DB::table('payments')->insertGetId($row);

            return (object) [...$row, 'reference' => $b->reference, 'service' => $b->service];
        });

        $gateway = $this->gateways->get($payment->gateway_code);

        try {
            return $gateway->checkout(new CheckoutRequest($payment->gateway_order_id, (int) $payment->amount, trim($payment->currency_code),
                $payment->idempotency_key, mb_substr("حجز {$payment->reference}: {$payment->service}", 0, 120),
                rtrim((string) config('raqam.site_url'), '/').config('raqam.payments.return_path')));
        } catch (\Throwable $e) {
            report($e);   // the attempt stays pending: the next try hands it back with the same key, the reconciliation settles it

            throw new PaymentRefused('gateway_failed', 'تعذّر الوصول إلى البنك الآن؛ حاول بعد قليل.');
        }
    }

    /**
     * Asks the bank about one attempt and records what it says (16.5). Final states are never touched again, so it is
     * safe to call from the customer's return, a notification and the reconciliation at the same moment.
     *
     * @return string the attempt's status afterwards
     */
    public function sync(int $paymentId): string
    {
        $p = DB::table('payments')->where('id', $paymentId)->first(['status', 'gateway_code', 'gateway_order_id', 'booking_id']);
        if ($p !== null && in_array($p->status, ['failed', 'cancelled'], true)) {
            $this->lateMoney($paymentId, $p);

            return $p->status;
        }
        if ($p === null || $p->status !== 'pending') {
            return $p->status ?? 'unknown';
        }
        $answer = $this->gateways->get($p->gateway_code)->status($p->gateway_order_id);

        return DB::transaction(function () use ($paymentId, $answer) {
            // The booking first, then the attempt: two attempts of one booking settle one after the other.
            $p = DB::selectOne('SELECT p.*, b.status_code AS booking_status, b.reference FROM payments p JOIN bookings b ON b.id = p.booking_id
                                WHERE p.id = ? FOR UPDATE OF b, p', [$paymentId]);
            if ($p->status !== 'pending') {
                return $p->status;
            }

            return match ($answer->state) {
                'paid' => $this->paid($p, $answer),
                'failed' => $this->close($p, 'failed', $answer->reason ?: 'رفض البنك الدفع.'),
                'cancelled' => $this->close($p, 'cancelled', null),
                default => $this->expired($p) ? $this->close($p, 'cancelled', null) : 'pending',
            };
        });
    }

    private function paid(object $p, GatewayPayment $answer): string
    {
        if ($answer->amount !== (int) $p->amount || $answer->currency !== trim($p->currency_code)) {
            PaymentAlerts::raise('mismatch:'.$p->id, 'دفعة لا يطابق مبلغها الحجز '.$p->reference,
                "البنك أبلغ عن {$answer->amount} {$answer->currency} والمطلوب {$p->amount} ".trim($p->currency_code).'. لم تُسجّل كمدفوعة؛ راجع البنك.');

            return $this->close($p, 'failed', 'المبلغ أو العملة لدى البنك لا يطابقان المطلوب.');
        }

        $paidBefore = DB::table('payments')->where('booking_id', $p->booking_id)->where('id', '<>', $p->id)->whereIn('status', ['authorized', 'captured'])->exists();
        DB::update('UPDATE payments SET status = ?, gateway_transaction_id = ?, paid_at = greatest(now(), created_at), failure_reason = ? WHERE id = ?',
            [$paidBefore ? 'duplicate' : 'captured', $answer->transactionId, $paidBefore ? 'دفعة ثانية لحجز مدفوع.' : null, $p->id]);

        if ($paidBefore) {
            PaymentAlerts::raise('duplicate:'.$p->id, 'دفعة مكررة للحجز '.$p->reference,
                'وصلت دفعة ثانية لحجز مدفوع ('.$p->amount.' '.trim($p->currency_code).'). لم تُسترجع تلقائياً؛ الاسترجاع بقرارك.');

            return 'duplicate';
        }

        if ($p->booking_status === 'awaiting_payment') {
            $this->statuses->move((int) $p->booking_id, 'paid', 'دفع إلكتروني');
        } else {
            // The admin moved or cancelled the booking while the customer was paying: the money is recorded, the admin decides.
            PaymentAlerts::raise('stage:'.$p->id, 'دفعة لحجز ليس بانتظار الدفع '.$p->reference,
                'وصل الدفع والحجز بمرحلة «'.$p->booking_status.'». راجعه وقرّر.');
        }
        $invoice = app(Invoices::class)->issue((int) $p->id);
        SendInvoice::dispatch($invoice)->afterCommit();

        return 'captured';
    }

    /**
     * An attempt closed here (given up after a day, or failed) that the bank says was paid after all: the money is real,
     * so the admins are told. The attempt is not reopened; the admin decides (refund, or record it by hand).
     */
    private function lateMoney(int $paymentId, object $p): void
    {
        if ($this->gateways->get($p->gateway_code)->status($p->gateway_order_id)->state === 'paid') {
            $reference = DB::table('bookings')->where('id', $p->booking_id)->value('reference');
            PaymentAlerts::raise('late:'.$paymentId, 'دفعة وصلت لمحاولة مغلقة '.$reference,
                'البنك يقول إن المحاولة '.$p->gateway_order_id.' دُفعت بعد أن أُغلقت هنا ('.$p->status.'). راجع البنك وقرّر: استرجاع أو تسجيل يدوي.');
        }
    }

    private function close(object $p, string $status, ?string $reason): string
    {
        DB::table('payments')->where('id', $p->id)->update(['status' => $status, 'failure_reason' => $reason]);

        return $status;
    }

    private function expired(object $p): bool
    {
        return (bool) DB::selectOne('SELECT ?::timestamptz < now() - make_interval(hours => ?) AS old', [$p->created_at, $this->setting('expire_hours')])->old;
    }

    private function setting(string $key): int
    {
        return max(1, (int) config('raqam.payments.'.$key));
    }
}
