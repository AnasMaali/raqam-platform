<?php

namespace App\Payments\Gateways;

use App\Payments\CheckoutRequest;
use App\Payments\GatewayNotification;
use App\Payments\GatewayPayment;
use App\Payments\GatewayRefund;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The test gateway (decision 16): behaves like a bank with a hosted payment page, keeps its orders in the cache, and
 * signs its notifications with `raqam.payments.fake_secret`. It is never used in production (Gateways refuses it).
 * Tests play the customer and the bank with pay(), decline() and signedNotification().
 */
final class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'X-Fake-Signature';

    public function code(): string
    {
        return 'fake';
    }

    public function checkout(CheckoutRequest $request): string
    {
        $order = self::order($request->orderId);
        if ($order !== null && $order['key'] !== $request->idempotencyKey) {
            throw new \RuntimeException('Order id reused with another idempotency key.');
        }
        $order ??= ['key' => $request->idempotencyKey, 'amount' => $request->amount, 'currency' => $request->currency, 'state' => 'pending',
            'transaction' => null, 'refunded' => 0, 'refunds' => [], 'pages' => 0];
        $order['pages']++;   // how many times the page was asked for: a retry must not open a second charge
        self::save($request->orderId, $order);

        return 'https://pay.fake-gateway.test/checkout/'.rawurlencode($request->orderId);
    }

    public function status(string $orderId): GatewayPayment
    {
        Cache::increment('fake-gateway:status-calls');
        $order = self::order($orderId);
        if ($order === null) {
            return new GatewayPayment('unknown');
        }

        return new GatewayPayment($order['state'], $order['paid_amount'] ?? $order['amount'], $order['currency'], $order['transaction'], $order['reason'] ?? null);
    }

    public function notification(Request $request): ?GatewayNotification
    {
        $secret = (string) config('raqam.payments.fake_secret');
        $given = (string) $request->header(self::SIGNATURE_HEADER);
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $given)) {
            return null;
        }
        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || ! is_string($body['id'] ?? null)) {
            return null;
        }

        return new GatewayNotification($body['id'], (string) ($body['type'] ?? 'unknown'), is_string($body['order_id'] ?? null) ? $body['order_id'] : null, $body);
    }

    public function refund(string $orderId, int $amount, string $currency, string $idempotencyKey): GatewayRefund
    {
        $order = self::order($orderId);
        if ($order === null || $order['state'] !== 'paid' || $order['currency'] !== $currency) {
            return new GatewayRefund(false, null, 'الطلب غير مدفوع لدى البوابة.');
        }
        if (isset($order['refunds'][$idempotencyKey])) {
            return new GatewayRefund(true, $order['refunds'][$idempotencyKey]);
        }
        if ($order['refunded'] + $amount > ($order['paid_amount'] ?? $order['amount'])) {
            return new GatewayRefund(false, null, 'المبلغ أكبر من المتبقي.');
        }
        $id = 'RF-'.bin2hex(random_bytes(6));
        $order['refunded'] += $amount;
        $order['refunds'][$idempotencyKey] = $id;
        self::save($orderId, $order);

        return new GatewayRefund(true, $id);
    }

    // ---- the customer and the bank, for tests -------------------------------------------------------------------

    /** The customer pays on the bank's page (optionally a different amount, as a tampered page would). */
    public static function pay(string $orderId, ?int $amount = null): string
    {
        $order = self::order($orderId) ?? throw new \RuntimeException("No fake order {$orderId}.");
        $order['state'] = 'paid';
        $order['transaction'] = 'TX-'.bin2hex(random_bytes(6));
        $order['paid_amount'] = $amount ?? $order['amount'];
        self::save($orderId, $order);

        return $order['transaction'];
    }

    public static function decline(string $orderId, string $reason = 'رفض البنك البطاقة'): void
    {
        $order = self::order($orderId) ?? throw new \RuntimeException("No fake order {$orderId}.");
        $order['state'] = 'failed';
        $order['reason'] = $reason;
        self::save($orderId, $order);
    }

    /** @return array<string, mixed>|null */
    public static function order(string $orderId): ?array
    {
        return Cache::get('fake-gateway:'.$orderId);
    }

    /** A notification body and the headers that sign it; $secret overrides the right one (a forged signature). */
    public static function signedNotification(string $eventId, string $orderId, string $type = 'payment.updated', ?string $secret = null): array
    {
        $body = json_encode(['id' => $eventId, 'type' => $type, 'order_id' => $orderId, 'state' => 'paid']);

        return [$body, [self::SIGNATURE_HEADER => hash_hmac('sha256', $body, $secret ?? (string) config('raqam.payments.fake_secret'))]];
    }

    /** @param  array<string, mixed>  $order */
    private static function save(string $orderId, array $order): void
    {
        Cache::put('fake-gateway:'.$orderId, $order, 86400);
    }
}
