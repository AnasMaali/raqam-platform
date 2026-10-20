<?php

namespace App\Search;

use Carbon\CarbonImmutable;

/**
 * A visitor's search, already checked: the filters are ids, keys from a fixed list and dates, never raw text,
 * so nothing a visitor types can become part of an engine filter or a SQL statement.
 */
final class SearchQuery
{
    public const PER_PAGE = 12;

    /** How many videos, writers, guests and tags come with the first page of items. */
    public const GROUP_LIMIT = 5;

    public readonly string $form;

    /** @var list<string> */
    public readonly array $terms;

    public function __construct(
        public readonly string $text,
        public readonly ?int $categoryId = null,
        public readonly ?string $sectionKey = null,
        public readonly ?string $kind = null,
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
        public readonly string $sort = 'relevance',
        public readonly int $page = 1,
    ) {
        $form = SearchForm::of($text);
        $this->form = $form->text;
        $this->terms = $form->words();
    }

    public function filtered(): bool
    {
        return $this->categoryId !== null || $this->sectionKey !== null || $this->kind !== null || $this->from !== null || $this->to !== null;
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }
}
