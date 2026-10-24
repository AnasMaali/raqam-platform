<?php

namespace App\Payments;

/** One payment attempt as the provider needs it: amounts in the currency's smallest unit (16.12), always with the currency (16.11). */
final readonly class CheckoutRequest
{
    public function __construct(
        public string $orderId,
        public int $amount,
        public string $currency,
        public string $idempotencyKey,
        public string $description,
        public string $returnUrl,
    ) {}
}
