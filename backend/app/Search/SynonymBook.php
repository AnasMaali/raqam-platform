<?php

namespace App\Search;

use Illuminate\Support\Facades\DB;

/**
 * The synonyms the admin manages (10.9), from synonym_groups / synonym_terms. Every word of a group finds the
 * others. They are sent in the search form, because that is the form documents and queries meet in.
 */
final class SynonymBook
{
    /** @return array<string, list<string>> Meilisearch `synonyms` setting */
    public static function forEngine(): array
    {
        $map = [];
        foreach (self::groups() as $terms) {
            $forms = array_values(array_unique(array_filter(array_map(fn (string $t) => SearchForm::text($t), $terms))));
            foreach ($forms as $form) {
                $others = array_values(array_diff($forms, [$form]));
                if ($others !== []) {
                    $map[$form] = array_values(array_unique([...($map[$form] ?? []), ...$others]));
                }
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * The other forms of a query word, for the PostgreSQL fallback (which has no synonyms of its own).
     *
     * @return list<string> including the word itself
     */
    public static function expand(string $form, ?array $book = null): array
    {
        $book ??= self::forEngine();

        return array_values(array_unique([$form, ...($book[$form] ?? [])]));
    }

    /** @return array<int, list<string>> terms by group id */
    public static function groups(): array
    {
        return DB::table('synonym_terms')->orderBy('group_id')->orderBy('term')->get()
            ->groupBy('group_id')->map(fn ($rows) => $rows->pluck('term')->all())->all();
    }
}
