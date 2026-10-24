<?php

namespace App\Payments;

/** An order as the provider reports it. $state: pending | paid | failed | cancelled | unknown (the provider has no such order). */
final readonly class GatewayPayment
{
    public function __construct(
        public string $state,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $transactionId = null,
        public ?string $reason = null,
    ) {}
}
