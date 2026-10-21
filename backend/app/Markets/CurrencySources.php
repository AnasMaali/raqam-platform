<?php

namespace App\Markets;

use App\Integrations\DataProviders;

/** The currency sources the code knows, by data_providers code, and the one in use. */
final class CurrencySources
{
    /** @var array<string, class-string<CurrencySource>> */
    public const ALL = [
        'bank_of_israel' => BankOfIsraelSource::class,
        'exchangerate_api' => ExchangeRateApiSource::class,
    ];

    public static function get(string $code): ?CurrencySource
    {
        return isset(self::ALL[$code]) ? app(self::ALL[$code]) : null;
    }

    /** The source the settings point at, or null when none is set or it is unknown to the code. */
    public static function active(): ?CurrencySource
    {
        $code = DataProviders::active('currency');

        return $code === null ? null : self::get($code);
    }
}
