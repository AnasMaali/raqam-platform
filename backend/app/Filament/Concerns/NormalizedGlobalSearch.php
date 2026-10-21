<?php

namespace App\Filament\Concerns;

use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The panel's quick search (10.15) the way the site searches: hamza, ta marbuta, alef maqsura and diacritics do
 * not matter, every word typed must appear. It runs on PostgreSQL (normalize_ar and the generated search_*
 * columns), not on Meilisearch, because admins must also find drafts, which are never in the engine.
 */
trait NormalizedGlobalSearch
{
    /** SQL of the normalised text a record is found by: a generated search_* column, or normalize_ar(...). */
    abstract protected static function globalSearchText(): string;

    public static function getGlobalSearchResultsLimit(): int
    {
        return 8;
    }

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $words = array_slice(array_values(array_filter(explode(' ', ArabicNormalizer::normalize(mb_substr($search, 0, 100))))), 0, 6);
        if ($words === []) {
            $query->whereRaw('false');

            return;
        }
        foreach ($words as $word) {
            $query->whereRaw(static::globalSearchText().' ILIKE ?', ['%'.addcslashes($word, '%_\\').'%']);
        }
    }
}
