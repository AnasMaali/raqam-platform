<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Prices as the admin types them and as they are stored (decision 14.2, 16.12): the amount is kept as a whole number of
 * the currency's smallest unit (agorot, cents), never a float. "16.12" is 1612. Typing is strict: digits (Western or
 * Arabic), one decimal point (. or ٫), at most the currency's decimals; no sign, no exponent, no thousands separators,
 * nothing above MAX. A wrong value is refused, never rounded.
 */
final class Money
{
    /** The largest price the admin may enter: 1,000,000.00 (in the currency's units). */
    public const MAX_UNITS = 1_000_000;

    /** @throws PriceRejected */
    public static function toMinor(string $input, int $decimals = 2): int
    {
        $text = strtr(trim($input), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        if ($text === '') {
            throw new PriceRejected('أدخل المبلغ.');
        }
        if (str_starts_with($text, '-')) {
            throw new PriceRejected('المبلغ لا يكون سالباً.');
        }
        if (! preg_match('/^\d{1,9}(\.\d+)?$/', $text)) {
            throw new PriceRejected('اكتب المبلغ بالأرقام فقط، مثل 123 أو 16.12 (بدون فواصل أو رموز).');
        }
        if (str_contains($text, '.') && strlen(explode('.', $text)[1]) > $decimals) {
            throw new PriceRejected('المبلغ بمنزلتين عشريتين على الأكثر (مثل 16.12).');
        }
        try {
            $amount = BigDecimal::of($text);
        } catch (MathException) {
            throw new PriceRejected('المبلغ غير صحيح.');
        }
        if ($amount->isGreaterThan(self::MAX_UNITS)) {
            throw new PriceRejected('المبلغ أكبر من المسموح ('.number_format(self::MAX_UNITS).').');
        }
        if ($amount->isZero()) {
            throw new PriceRejected('المبلغ أكبر من صفر. إذا لم يكن هناك سعر محدد اختر «السعر عند التواصل».');
        }

        return $amount->withPointMovedRight($decimals)->toScale(0, RoundingMode::UNNECESSARY)->toInt();
    }

    /** 1612 → "16.12" (always the currency's decimals). */
    public static function fromMinor(int $minor, int $decimals = 2): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, $decimals);
    }

    /** 123456 → "1,234.56": for the page, with Western digits (1.3). */
    public static function display(int $minor, int $decimals = 2): string
    {
        [$whole, $fraction] = array_pad(explode('.', self::fromMinor($minor, $decimals)), 2, '');

        return number_format((int) $whole).($fraction === '' ? '' : '.'.$fraction);
    }
}
