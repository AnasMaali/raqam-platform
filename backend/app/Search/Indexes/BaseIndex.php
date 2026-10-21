<?php

namespace App\Search\Indexes;

use App\Search\SearchForm;
use Illuminate\Database\Query\Builder;

/**
 * Shared settings: every searchable field is a `_n` field in the search form, and the language of every one of
 * them is pinned to Arabic. Meilisearch guesses the language of each text otherwise, guesses short Arabic wrong,
 * and then tokenises the document and the query differently (decision after spike B: final).
 */
abstract class BaseIndex implements SearchIndex
{
    public const LOCALES = ['ara'];

    protected const CHUNK = 500;

    /** @return list<string> */
    abstract protected function searchable(): array;

    /** The rows that belong (the one place each index states its rule). */
    abstract public function belonging(): Builder;

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return [
            'searchableAttributes' => $this->searchable(),
            'displayedAttributes' => $this->displayed(),
            'filterableAttributes' => $this->filterable(),
            'sortableAttributes' => $this->sortable(),
            'localizedAttributes' => [['attributePatterns' => ['*_n'], 'locales' => self::LOCALES]],
        ];
    }

    public function ids(): iterable
    {
        $query = $this->belonging();
        $column = $query->from.'.id';
        $last = 0;
        do {
            $chunk = (clone $query)->where($column, '>', $last)->orderBy($column)->limit(self::CHUNK)->pluck($column)->map(fn ($id) => (int) $id)->all();
            if ($chunk !== []) {
                yield $chunk;
                $last = end($chunk);
            }
        } while (count($chunk) === self::CHUNK);
    }

    /** @return list<string> */
    protected function displayed(): array
    {
        return ['id', ...array_slice($this->searchable(), 0, 2)];
    }

    /** @return list<string> */
    protected function filterable(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function sortable(): array
    {
        return [];
    }

    protected static function form(?string ...$texts): string
    {
        return SearchForm::text(implode(' ', array_filter($texts, fn ($t) => $t !== null && $t !== '')));
    }
}
