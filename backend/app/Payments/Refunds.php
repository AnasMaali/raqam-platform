<?php

namespace App\Payments;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Models\User;
use App\Services\Money;
use Illuminate\Support\Facades\DB;

/**
 * Gives back all or part of a payment through its bank (16.10). Never more than what is left of it: checked here under a
 * lock and again by the database. The refund row is written before the bank is asked and its id is the idempotency key,
 * so a refund interrupted half-way is finished by the reconciliation, never sent twice. A duplicate payment keeps its
 * `duplicate` status after a refund (the refunds tell how much went back); any other becomes refunded or partially so.
 */
final class Refunds
{
    public function __construct(private Gateways $gateways) {}

    /** @throws PaymentRefused */
    public function refund(int $paymentId, int $amount, ?string $reason, ?User $actor): int
    {
        if ($amount <= 0) {
            throw new PaymentRefused('too_much', 'مبلغ الاسترجاع يجب أن يكون أكبر من صفر.');
        }
        $refundId = DB::transaction(function () use ($paymentId, $amount, $reason, $actor) {
            $p = DB::selectOne('SELECT id, amount, status FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p === null || ! in_array($p->status, ['captured', 'partially_refunded', 'duplicate'], true)) {
                throw new PaymentRefused('not_refundable', 'هذه الدفعة لا تُسترجع.');
            }
            $left = (int) $p->amount - (int) DB::table('refunds')->where('payment_id', $paymentId)->where('status', '<>', 'failed')->sum('amount');
            if ($amount > $left) {
                throw new PaymentRefused('too_much', 'مبلغ الاسترجاع أكبر من المتبقي من الدفعة.');
            }

            return DB::table('refunds')->insertGetId(['payment_id' => $paymentId, 'amount' => $amount,
                'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500), 'created_by' => $actor?->id]);
        });

        return $this->send($refundId) ? $refundId : throw new PaymentRefused('gateway_failed', 'رفض البنك الاسترجاع؛ لم يُسترجع شيء.');
    }

    /** Asks the bank for a written refund (again, with the same key, when it was interrupted); returns whether it went. */
    public function send(int $refundId): bool
    {
        $r = DB::selectOne('SELECT r.id, r.amount, r.status, p.gateway_code, p.gateway_order_id, p.currency_code
                            FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE r.id = ?', [$refundId]);
        if ($r->status !== 'pending') {
            return $r->status === 'succeeded';
        }
        $answer = $this->gateways->get($r->gateway_code)->refund($r->gateway_order_id, (int) $r->amount, trim($r->currency_code), 'refund-'.$refundId);

        DB::transaction(function () use ($refundId, $answer) {
            $r = DB::selectOne('SELECT r.*, p.amount AS paid, p.status AS payment_status FROM refunds r JOIN payments p ON p.id = r.payment_id
                                WHERE r.id = ? FOR UPDATE OF p, r', [$refundId]);
            if ($r->status !== 'pending') {
                return;
            }
            DB::table('refunds')->where('id', $refundId)->update(['status' => $answer->succeeded ? 'succeeded' : 'failed', 'gateway_refund_id' => $answer->refundId]);
            if (! $answer->succeeded) {
                PaymentAlerts::raise('refund:'.$refundId, 'رفض البنك استرجاعاً', $answer->reason ?? 'بدون سبب من البنك.');

                return;
            }
            if ($r->payment_status !== 'duplicate') {
                $back = (int) DB::table('refunds')->where('payment_id', $r->payment_id)->where('status', 'succeeded')->sum('amount');
                DB::table('payments')->where('id', $r->payment_id)->update(['status' => $back >= (int) $r->paid ? 'refunded' : 'partially_refunded']);
            }
            $to = DB::selectOne('SELECT c.name, c.email, b.reference, cur.symbol, cur.minor_units FROM payments p JOIN bookings b ON b.id = p.booking_id
                                 JOIN customers c ON c.id = b.customer_id JOIN currencies cur ON cur.code = p.currency_code WHERE p.id = ?', [$r->payment_id]);
            DB::afterCommit(fn () => SystemMail::queue(new TemplatedEmail('payment_refunded', ['الاسم' => $to->name, 'المرجع' => $to->reference,
                'المبلغ' => Money::display((int) $r->amount, (int) $to->minor_units).' '.$to->symbol]), (string) $to->email));
        });

        return $answer->succeeded;
    }
}
