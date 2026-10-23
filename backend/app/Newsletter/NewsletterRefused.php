<?php

namespace App\Newsletter;

/** A newsletter request refused for a reason that is not a field (too many, Turnstile, a wrong or expired code). */
final class NewsletterRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
