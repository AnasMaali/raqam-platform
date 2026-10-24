<?php

namespace App\Payments;

use Illuminate\Http\Request;

/**
 * One payment provider (16.2). Bank of Palestine is one of them; changing the bank means adding a class, nothing else.
 * Card details are typed on the provider's own page (16.3): nothing here ever carries a card number.
 */
interface PaymentGateway
{
    public function code(): string;

    /**
     * Opens the provider's payment page for one attempt. Called again with the same idempotency key, it must answer with
     * the same page and never charge twice (16.8).
     */
    public function checkout(CheckoutRequest $request): string;

    /** What the provider says about an order now, asked server to server (16.5): the only answer "paid" is believed from. */
    public function status(string $orderId): GatewayPayment;

    /** A notification the provider sent, or null when its signature is not the provider's (16.6). */
    public function notification(Request $request): ?GatewayNotification;

    /** Gives back part or all of a payment (16.10); the idempotency key keeps a retried request from refunding twice. */
    public function refund(string $orderId, int $amount, string $currency, string $idempotencyKey): GatewayRefund;
}
