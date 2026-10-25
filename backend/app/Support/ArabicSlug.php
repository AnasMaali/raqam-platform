<?php

namespace App\Support;

/**
 * Builds a slug that passes the slug_text domain (database/schema/00-foundation.sql):
 * no whitespace or / ? # % &, no diacritics, lower case, digits 0-9 (decision 1.3). Arabic letters are kept as they are.
 */
final class ArabicSlug
{
    private const MAX_LENGTH = 200;

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** Words that add nothing to an address (decision 23.4). Whole words only. */
    private const STOP_WORDS = [
        'في', 'من', 'على', 'إلى', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'تلك', 'التي', 'الذي', 'الذين', 'اللذين',
        'أن', 'إن', 'ان', 'أنه', 'انه', 'أنها', 'انها', 'كان', 'كانت', 'يكون', 'قد', 'لقد', 'هل', 'ما', 'ماذا', 'لا', 'لم', 'لن',
        'أو', 'او', 'ثم', 'بعد', 'قبل', 'حتى', 'عند', 'بين', 'هو', 'هي', 'هم', 'نحن', 'كل', 'بعض', 'حول', 'خلال', 'ضمن', 'لدى',
        'أمام', 'امام', 'منذ', 'إذا', 'اذا', 'كما', 'لكن', 'بل', 'ليس', 'التي', 'وقد', 'أي', 'اي', 'فقط', 'جدا', 'جداً',
    ];

    /** A title's address without the filler words, and not longer than a dozen words. Keeps the title whole if too little would be left. */
    public static function forTitle(?string $title, int $maxWords = 12): string
    {
        $full = self::make($title);
        $words = array_values(array_filter(explode('-', $full), fn ($w) => $w !== ''));
        $kept = array_values(array_filter($words, fn ($w) => ! in_array($w, self::STOP_WORDS, true)));

        $chosen = count($kept) >= 2 ? $kept : $words;

        return implode('-', array_slice($chosen, 0, $maxWords));
    }

    public static function make(?string $text): string
    {
        // Digits are the ordinary 0-9 everywhere on the site (decision 1.3), addresses included: Arabic-Indic (٠-٩) and
        // Eastern Arabic-Indic (۰-۹) digits become 0-9, so «الحلقة ١٢» and «الحلقة 12» get the same address.
        $text = strtr((string) $text, self::DIGITS);
        $slug = preg_replace('/[\x{0640}\p{Mn}]+/u', '', $text);   // tatweel and diacritics vanish; they are not word breaks
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);              // everything else becomes a separator
        $slug = trim(mb_strtolower($slug), '-');

        return rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
    }
}
