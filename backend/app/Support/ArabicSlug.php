<?php

namespace App\Support;

/**
 * Builds a slug that passes the slug_text domain (database/schema/00-foundation.sql):
 * no whitespace or / ? # % &, no diacritics, lower case. Arabic letters are kept as they are.
 */
final class ArabicSlug
{
    private const MAX_LENGTH = 200;

    public static function make(?string $text): string
    {
        $slug = preg_replace('/[\x{0640}\p{Mn}]+/u', '', (string) $text);   // tatweel and diacritics vanish; they are not word breaks
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);              // everything else becomes a separator
        $slug = trim(mb_strtolower($slug), '-');

        return rtrim(mb_substr($slug, 0, self::MAX_LENGTH), '-');
    }
}
