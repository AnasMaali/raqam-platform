<?php

namespace App\Mail;

use Carbon\CarbonInterface;

/** A sign-in from a device this account has not used (2.4). Sent straight away, never queued. */
class NewDeviceSignIn extends TemplatedEmail
{
    public readonly string $time;

    public function __construct(public readonly string $browser, public readonly string $os, CarbonInterface $at)
    {
        $this->time = $at->copy()->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
        parent::__construct('admin_new_device', ['الوقت' => $this->time, 'المتصفح' => $browser, 'النظام' => $os]);
    }
}
