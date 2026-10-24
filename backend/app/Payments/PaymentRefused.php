<?php

namespace App\Payments;

/** A payment, a refund or a gateway was refused; the message is Arabic and ready to show. */
final class PaymentRefused extends \RuntimeException
{
    /** @param  string  $reason  off | not_payable | already_paid | card_data | no_gateway | too_much | not_refundable | gateway_failed */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
