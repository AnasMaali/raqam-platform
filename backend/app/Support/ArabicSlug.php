<?php

namespace App\Support;

/**
 * Builds a slug that passes the slug_text domain (database/schema/00-foundation.sql):
 * no whitespace or / ? # % &, no diacritics, lower case. Arabic letters are kept as they are.
 */
final class ArabicSlug
{
    private const MAX_LENGTH = 200;

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
        $slug = preg_replace('/[\x{0640}\p{Mn}]+/u', '', (string) $text);   // tatweel and diacritics vanish; they are not word breaks
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);              // everything else becomes a separator
        $slug = trim(mb_strtolower($slug), '-');

        return rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
    }
}
