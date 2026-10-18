<?php

namespace App\Content;

/** A rule of the content refused a save; the message is Arabic and `field` is the form field it belongs to, if any. */
final class PostRuleViolation extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
