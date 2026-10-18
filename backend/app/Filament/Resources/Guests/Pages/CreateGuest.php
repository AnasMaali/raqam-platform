<?php

namespace App\Filament\Resources\Guests\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPersonLinks;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Guests\GuestResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;

class CreateGuest extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesPersonLinks, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterCreate as private saveSeo;
    }

    protected static string $resource = GuestResource::class;

    private string $linksOwner = 'guests';

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takeLinks($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('guests', $data['slug'] ?: $data['name'], null, 'guest');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->saveSeo();
        $this->saveLinks();
    }
}
