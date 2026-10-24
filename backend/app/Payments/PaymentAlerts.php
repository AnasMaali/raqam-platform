<?php

namespace App\Payments;

use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;

/** Something about a payment needs a person (a duplicate, an amount that does not match, a refund the bank refused): bell and e-mail. */
final class PaymentAlerts
{
    public static function raise(string $key, string $title, string $body): void
    {
        AdminAlerts::raise('system', 'payment:'.$key, $title, $body, null,
            fn () => new TemplatedEmail('admin_payment_alert', ['الموضوع' => $title, 'التفاصيل' => $body]), windowMinutes: 1);
    }
}
