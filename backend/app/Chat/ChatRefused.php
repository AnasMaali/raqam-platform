<?php

namespace App\Chat;

/** A chat message refused: too many, a link or code, too long, or the chatbot is off. */
final class ChatRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
