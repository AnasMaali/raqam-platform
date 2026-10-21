<?php

namespace App\Markets;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Arabic names and symbols for the currencies a source can publish, so adding one to the board is a choice from a
 * list (11.1); the admin can still change the name and symbol. Spreads are typed as a percentage and kept in basis points.
 */
final class CurrencyCatalog
{
    /** @var array<string, array{0: string, 1: string}> code => [name, symbol] */
    public const KNOWN = [
        'USD' => ['دولار أمريكي', '$'],
        'JOD' => ['دينار أردني', 'د.أ'],
        'EUR' => ['يورو', '€'],
        'GBP' => ['جنيه إسترليني', '£'],
        'EGP' => ['جنيه مصري', 'ج.م'],
        'AED' => ['درهم إماراتي', 'د.إ'],
        'SAR' => ['ريال سعودي', 'ر.س'],
        'JPY' => ['ين ياباني', '¥'],
        'CHF' => ['فرنك سويسري', 'CHF'],
        'CAD' => ['دولار كندي', 'C$'],
        'AUD' => ['دولار أسترالي', 'A$'],
        'SEK' => ['كرونة سويدية', 'kr'],
        'NOK' => ['كرونة نرويجية', 'kr'],
        'DKK' => ['كرونة دنماركية', 'kr'],
        'ZAR' => ['راند جنوب أفريقي', 'R'],
        'LBP' => ['ليرة لبنانية', 'ل.ل'],
    ];

    /** The highest spread the screen accepts, in per cent. */
    public const MAX_SPREAD_PERCENT = 20;

    public static function name(string $code): string
    {
        return self::KNOWN[$code][0] ?? $code;
    }

    public static function symbol(string $code): string
    {
        return self::KNOWN[$code][1] ?? $code;
    }

    /** "0.5" (per cent) → 50 basis points; null when it is not a percentage with at most two decimals within the limit. */
    public static function basisPoints(string $percent): ?int
    {
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', trim($percent)) !== 1) {
            return null;
        }
        try {
            $bp = BigDecimal::of(trim($percent))->multipliedBy(100)->toBigInteger()->toInt();
        } catch (MathException) {
            return null;
        }

        return $bp <= self::MAX_SPREAD_PERCENT * 100 ? $bp : null;
    }

    public static function percent(int $basisPoints): string
    {
        return (string) BigDecimal::ofUnscaledValue($basisPoints, 2)->stripTrailingZeros();
    }
}
