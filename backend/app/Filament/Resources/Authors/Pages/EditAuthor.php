<?php

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPersonLinks;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Authors\AuthorResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;

class EditAuthor extends EditRecord
{
    use ReportsDatabaseRefusals, SavesPersonLinks, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = AuthorResource::class;

    private string $linksOwner = 'authors';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillLinks($this->fillSeo($data));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takeLinks($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('authors', $data['slug'] ?: $data['name'], $this->getRecord()->id, 'author');

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveSeo();
        $this->saveLinks();
    }
}
