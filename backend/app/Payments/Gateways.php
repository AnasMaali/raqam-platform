<?php

namespace App\Payments;

use App\Payments\Gateways\FakeGateway;
use Illuminate\Support\Facades\DB;

/**
 * Which provider takes payments: the active row of payment_gateways, with the class that speaks to it. A provider whose
 * connection is not written yet (Bank of Palestine, until its API documents arrive) cannot be used. The test gateway is
 * refused in production whatever the table says.
 */
final class Gateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    public const CONNECTORS = ['fake' => FakeGateway::class];

    /** @throws PaymentRefused */
    public function active(): PaymentGateway
    {
        $codes = DB::table('payment_gateways')->where('is_active', true)->pluck('code');
        if ($codes->count() !== 1) {
            throw new PaymentRefused('no_gateway', 'لا توجد بوابة دفع مفعّلة.');
        }

        return $this->get($codes->first());
    }

    /** @throws PaymentRefused */
    public function get(string $code): PaymentGateway
    {
        if ($code === 'fake' && app()->isProduction()) {
            throw new PaymentRefused('no_gateway', 'البوابة التجريبية لا تعمل في الإنتاج.');
        }
        $class = self::CONNECTORS[$code] ?? throw new PaymentRefused('no_gateway', 'بوابة الدفع هذه غير مربوطة بعد.');

        return app($class);
    }
}
