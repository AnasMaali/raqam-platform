<?php

namespace App\Awareness;

/** A character action that was refused; the message is Arabic, `field` names the form field it is about. */
final class CharacterRejected extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'poses')
    {
        parent::__construct($message);
    }
}
