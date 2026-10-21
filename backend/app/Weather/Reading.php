<?php

namespace App\Weather;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Checks for single values of a weather answer. Each returns null for anything outside what the place can see. */
final class Reading
{
    /** Palestine's records are about −10 °C and 54 °C; this leaves room without letting nonsense through. */
    public const MIN_C = -30;

    public const MAX_C = 60;

    /** km/h; above this is a broken answer, not a storm. */
    public const MAX_WIND = 300;

    public static function celsius(mixed $value): ?string
    {
        $number = self::decimal($value);
        if ($number === null || $number->isLessThan(self::MIN_C) || $number->isGreaterThan(self::MAX_C)) {
            return null;
        }

        return (string) $number->toScale(1, RoundingMode::HALF_UP);
    }

    /** m/s (the metric unit of the source) → km/h, one decimal. */
    public static function kmh(mixed $value): ?string
    {
        $number = self::decimal($value);
        if ($number === null || $number->isNegative()) {
            return null;
        }
        $kmh = $number->multipliedBy('3.6')->toScale(1, RoundingMode::HALF_UP);

        return $kmh->isGreaterThan(self::MAX_WIND) ? null : (string) $kmh;
    }

    public static function integer(mixed $value, int $min, int $max): ?int
    {
        if (! is_string($value) || preg_match('/^-?\d{1,6}(\.0+)?$/', $value) !== 1) {
            return null;
        }
        $int = (int) $value;

        return $int >= $min && $int <= $max ? $int : null;
    }

    /** A Unix time no more than 15 minutes ahead (or $aheadDays for a forecast) and no more than a day old. */
    public static function unixTime(mixed $value, int $aheadDays = 0): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{9,11}$/', $value) !== 1) {
            return null;
        }
        $time = CarbonImmutable::createFromTimestampUTC((int) $value);
        $now = CarbonImmutable::now();
        $latest = $aheadDays > 0 ? $now->addDays($aheadDays) : $now->addMinutes(15);

        return $time->lessThanOrEqualTo($latest) && $time->greaterThan($now->subDay()) ? $time : null;
    }

    private static function decimal(mixed $value): ?BigDecimal
    {
        return is_string($value) && preg_match('/^-?\d{1,4}(\.\d{1,6})?$/', $value) === 1 ? BigDecimal::of($value) : null;
    }
}
