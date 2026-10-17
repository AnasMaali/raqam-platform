<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPageText;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Pages\PageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesPageText, SavesSeoMeta;

    protected static string $resource = PageResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->preparePage($this->takeSeo($data));
    }
}
