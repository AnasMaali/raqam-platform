<?php

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPersonLinks;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Authors\AuthorResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;

class CreateAuthor extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesPersonLinks, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterCreate as private saveSeo;
    }

    protected static string $resource = AuthorResource::class;

    private string $linksOwner = 'authors';

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takeLinks($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('authors', $data['slug'] ?: $data['name'], null, 'author');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->saveSeo();
        $this->saveLinks();
    }
}
