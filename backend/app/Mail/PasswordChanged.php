<?php

namespace App\Mail;

use Carbon\CarbonInterface;

/** The admin's password was changed (2.4). Sent straight away, never queued. */
class PasswordChanged extends TemplatedEmail
{
    public readonly string $time;

    public function __construct(public readonly string $browser, public readonly string $os, CarbonInterface $at)
    {
        $this->time = $at->copy()->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
        parent::__construct('admin_password_changed', ['الوقت' => $this->time, 'المتصفح' => $browser, 'النظام' => $os]);
    }
}
