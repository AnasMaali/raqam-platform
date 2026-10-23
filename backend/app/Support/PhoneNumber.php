<?php

namespace App\Support;

/**
 * A phone number as a visitor types it, made into the E.164 form the database keeps (+970599123456). Arabic digits,
 * spaces, dashes, dots and brackets are fine and "00" means "+". A local number (059…) is refused rather than guessed:
 * it could be +970 or +972.
 */
final class PhoneNumber
{
    public static function e164(?string $typed): ?string
    {
        if ($typed === null) {
            return null;
        }
        $digits = strtr(trim($typed), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        $digits = (string) preg_replace('/[\s\-\.\(\)\x{200E}\x{200F}]/u', '', $digits);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        return preg_match('/^\+[1-9][0-9]{6,14}$/', $digits) === 1 ? $digits : null;
    }
}
