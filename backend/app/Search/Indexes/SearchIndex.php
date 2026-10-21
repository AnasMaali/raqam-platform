<?php

namespace App\Search\Indexes;

/**
 * One Meilisearch index. The index holds exactly the rows a visitor may see; the rule for "may see" is written
 * once per index, in documents() and ids(), as a database query, and nowhere else.
 */
interface SearchIndex
{
    /** Short name; the index is called prefix + key. */
    public function key(): string;

    /** @return array<string, mixed> Meilisearch settings, without synonyms (the indexer adds them) */
    public function settings(): array;

    /**
     * The documents of those ids that belong in the index now. An id that is missing from the result does not
     * belong (draft, trashed, hidden, gone) and is removed.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function documents(array $ids): array;

    /** @return iterable<list<int>> every id that belongs, in chunks */
    public function ids(): iterable;
}
