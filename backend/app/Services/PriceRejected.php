<?php

namespace App\Services;

use RuntimeException;

/** A service or price that cannot be saved as given; the message is the Arabic sentence for the admin. */
final class PriceRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
