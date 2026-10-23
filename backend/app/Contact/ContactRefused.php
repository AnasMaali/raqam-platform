<?php

namespace App\Contact;

/** The contact form was refused for a reason that is not a field: too many messages, or Turnstile. */
final class ContactRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
