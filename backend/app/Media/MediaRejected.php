<?php

namespace App\Media;

/**
 * An upload that was refused. The message is Arabic and safe to show to the person who uploaded;
 * `field` says which form field it is about.
 */
final class MediaRejected extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'file')
    {
        parent::__construct($message);
    }
}
