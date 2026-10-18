<?php

namespace App\Filament\Resources\Guests\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPersonLinks;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Guests\GuestResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;

class EditGuest extends EditRecord
{
    use ReportsDatabaseRefusals, SavesPersonLinks, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = GuestResource::class;

    private string $linksOwner = 'guests';

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillLinks($this->fillSeo($data));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takeLinks($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('guests', $data['slug'] ?: $data['name'], $this->getRecord()->id, 'guest');

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveSeo();
        $this->saveLinks();
    }
}
