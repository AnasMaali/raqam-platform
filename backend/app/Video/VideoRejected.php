<?php

namespace App\Video;

/** A video action that was refused. The message is Arabic and safe to show; `field` names the form field it is about. */
final class VideoRejected extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'url', public readonly bool $manualEntry = false)
    {
        parent::__construct($message);
    }
}
