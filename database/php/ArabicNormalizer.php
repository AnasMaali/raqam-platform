<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Arabic search normalisation (decision 10.2).
 *
 * MUST stay identical to the PostgreSQL function normalize_ar() in
 * database/schema/00-foundation.sql — tests/normalizer_parity.py checks both
 * against the same cases. Used for:
 *   - the text sent to Meilisearch when indexing (searchable fields)
 *   - the visitor's query before it is sent to Meilisearch
 *   - the PostgreSQL fallback search (via the generated search_* columns)
 */
final class ArabicNormalizer
{
    private const MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // tashkeel U+064B–U+065F, superscript alef U+0670, tatweel U+0640
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, self::MAP);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text, ' ');
    }
}
