<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPageText;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Pages\PageResource;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    use ReportsDatabaseRefusals, SavesPageText, SavesSeoMeta;

    protected static string $resource = PageResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->preparePage($this->takeSeo($data), $this->getRecord()->id);
    }
}
