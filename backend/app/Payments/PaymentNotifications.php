<?php

namespace App\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A notification from a bank (16.6). Every one is kept in payment_events, even one whose signature is wrong (kept under
 * an id of its own, so a forged message can never take the id a real one will use). A real one is acted on once: the
 * event row is locked and marked processed; and what it says is not believed — the order is asked about again.
 */
final class PaymentNotifications
{
    public function __construct(private Gateways $gateways, private Payments $payments) {}

    /**
     * @return string accepted | duplicate | invalid
     *
     * @throws PaymentRefused for a gateway that is not connected
     */
    public function receive(string $gatewayCode, Request $request): string
    {
        $gateway = $this->gateways->get($gatewayCode);
        $notice = $gateway->notification($request);
        $content = $request->getContent();
        $payload = json_decode($content, true);
        $payload = is_array($payload) ? $payload : ['raw' => mb_substr($content, 0, 10000)];
        $eventId = $notice?->eventId ?? 'invalid-'.hash('sha256', $content);

        DB::statement('INSERT INTO payment_events (gateway_code, gateway_event_id, event_type, signature_valid, payload)
                       VALUES (?, ?, ?, ?, ?::jsonb) ON CONFLICT (gateway_code, gateway_event_id) DO NOTHING',
            [$gatewayCode, mb_substr($eventId, 0, 100), mb_substr($notice?->type ?? 'invalid_signature', 0, 60), $notice !== null, json_encode($payload)]);
        if ($notice === null) {
            return 'invalid';
        }

        return DB::transaction(function () use ($gatewayCode, $notice) {
            $event = DB::selectOne('SELECT id, processed_at FROM payment_events WHERE gateway_code = ? AND gateway_event_id = ? FOR UPDATE',
                [$gatewayCode, mb_substr($notice->eventId, 0, 100)]);
            if ($event->processed_at !== null) {
                return 'duplicate';
            }
            $paymentId = $notice->orderId === null ? null
                : DB::table('payments')->where('gateway_code', $gatewayCode)->where('gateway_order_id', $notice->orderId)->value('id');
            if ($paymentId !== null) {
                $this->payments->sync((int) $paymentId);
            }
            DB::update('UPDATE payment_events SET payment_id = ?, processed_at = greatest(now(), received_at) WHERE id = ?', [$paymentId, $event->id]);

            return 'accepted';
        });
    }
}
