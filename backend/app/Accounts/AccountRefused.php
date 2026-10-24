<?php

namespace App\Accounts;

/** A customer-account request was refused; the message is Arabic and ready to show. */
final class AccountRefused extends \RuntimeException
{
    /** @param  string  $reason  too_many | turnstile_failed | bad_code | expired | bad_credentials | exists | open_booking | not_found */
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
