<?php

namespace App\Search;

/**
 * What a search found, before it is shown: ids in order, and for each the match positions in its search-form
 * fields. Nothing here is shown as is: the API reads every row again through the visibility rules.
 */
final class SearchResults
{
    /**
     * @param  list<array{id: int, fields: array<string, string>, matches: array<string, list<array{start: int, length: int}>>}>  $posts
     * @param  array<string, list<array{id: int, fields: array<string, string>, matches: array<string, list<array{start: int, length: int}>>}>>  $groups  videos, authors, guests, tags
     */
    public function __construct(
        public readonly string $engine,
        public readonly array $posts,
        public readonly int $total,
        public readonly array $groups = [],
    ) {}

    public const ENGINE = 'meilisearch';

    public const FALLBACK = 'database';

    public function isEmpty(): bool
    {
        return $this->total === 0 && array_filter($this->groups) === [];
    }
}
