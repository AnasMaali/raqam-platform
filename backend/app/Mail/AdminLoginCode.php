<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminLoginCode extends Mailable
{
    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'رمز الدخول إلى لوحة رَقَم');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-login-code');
    }
}
