<?php

namespace App\Mail;

/** The 6-digit sign-in code (2.4). Sent straight away, never queued (decision 2026-10-23). */
class AdminLoginCode extends TemplatedEmail
{
    public function __construct(public readonly string $code, public readonly int $minutes)
    {
        parent::__construct('admin_login_code', ['الرمز' => $code, 'الدقائق' => $minutes]);
    }
}
