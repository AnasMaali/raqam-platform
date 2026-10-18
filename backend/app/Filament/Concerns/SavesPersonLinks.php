<?php

namespace App\Filament\Concerns;

use App\Taxonomy\PersonLinks;
use Illuminate\Support\Arr;

/**
 * For the create and edit pages of an author or a guest: fills and saves the social links, kept in their
 * own table. The page says which table with $linksOwner ('authors' or 'guests').
 */
trait SavesPersonLinks
{
    /** @var array<string, mixed> */
    private array $linkData = [];

    protected function fillLinks(array $data): array
    {
        $data['links'] = app(PersonLinks::class)->load($this->linksOwner, $this->getRecord()->id);

        return $data;
    }

    protected function takeLinks(array $data): array
    {
        $this->linkData = Arr::pull($data, 'links') ?? [];

        return $data;
    }

    protected function saveLinks(): void
    {
        app(PersonLinks::class)->sync($this->linksOwner, $this->getRecord()->id, $this->linkData);
    }
}
