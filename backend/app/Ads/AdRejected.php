<?php

namespace App\Ads;

use RuntimeException;

/** A campaign, slot or house ad that cannot be saved as given; the message is the Arabic sentence for the admin. */
final class AdRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
