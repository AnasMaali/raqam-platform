<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Categories\CategoryResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    use ReportsDatabaseRefusals, SavesSeoMeta;

    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takeSeo($data);
        $data['slug'] = UniqueSlug::for('categories', $data['slug'] ?: $data['name_ar'], $this->getRecord()->id, 'category');

        return $data;
    }
}
