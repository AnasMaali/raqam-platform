<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordChanged extends Mailable
{
    public readonly string $time;

    public function __construct(public readonly string $browser, public readonly string $os, CarbonInterface $at)
    {
        $this->time = $at->copy()->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تم تغيير كلمة المرور');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-changed');
    }
}
