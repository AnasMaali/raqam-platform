<?php

namespace App\Search\Experiment;

/**
 * Spike only: the candidate fixes for the definite article and broken plurals, applied on top of
 * ArabicNormalizer (which stays untouched). Nothing here is used outside `search:evaluate`.
 */
final class Variants
{
    /** Words that have no Arabic prefix removed: normalised forms, bidirectional. */
    private const SYNONYM_GROUPS = [
        ['سعر', 'اسعار'], ['مصنع', 'مصانع'], ['تاجر', 'تجار'], ['سوق', 'اسواق'], ['بنك', 'بنوك'],
    ];

    /** @return array<string, array{strip: bool, synonyms: bool, locale: bool}> */
    public static function all(): array
    {
        return [
            'auto-language' => ['strip' => false, 'synonyms' => false, 'locale' => false],
            'base' => ['strip' => false, 'synonyms' => false, 'locale' => true],
            'strip_al' => ['strip' => true, 'synonyms' => false, 'locale' => true],
            'synonyms' => ['strip' => false, 'synonyms' => true, 'locale' => true],
            'strip_al+synonyms' => ['strip' => true, 'synonyms' => true, 'locale' => true],
        ];
    }

    /** Removes a leading ال (also وال بال كال فال لل) from every word that keeps at least three letters. */
    public static function stripArticle(string $normalised): string
    {
        return preg_replace_callback('/\S+/u', function (array $word) {
            foreach (['وال', 'فال', 'بال', 'كال', 'لل', 'ال'] as $prefix) {
                if (str_starts_with($word[0], $prefix) && mb_strlen($word[0]) - mb_strlen($prefix) >= 3) {
                    return mb_substr($word[0], mb_strlen($prefix));
                }
            }

            return $word[0];
        }, $normalised);
    }

    /** @return array<string, list<string>> */
    public static function synonyms(): array
    {
        $map = [];
        foreach (self::SYNONYM_GROUPS as $group) {
            foreach ($group as $word) {
                $map[$word] = array_values(array_diff($group, [$word]));
            }
        }

        return $map;
    }
}
