<?php

namespace App\Payments;

final readonly class GatewayRefund
{
    public function __construct(public bool $succeeded, public ?string $refundId = null, public ?string $reason = null) {}
}
