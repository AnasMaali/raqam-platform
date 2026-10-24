<?php

namespace App\Booking;

/** A booking request or a status change was refused; the message is Arabic and ready to show. */
final class BookingRefused extends \RuntimeException
{
    /** @param  string  $reason  off | too_many | turnstile_failed | not_allowed | payment_off | unknown_status */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
