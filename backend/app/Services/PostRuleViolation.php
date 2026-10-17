<?php

namespace App\Services;

use RuntimeException;

/** A publishing rule the database refused, with a message the editor can read. */
class PostRuleViolation extends RuntimeException
{
    /** @param  string|null  $field  form field the message belongs to, when there is one */
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
