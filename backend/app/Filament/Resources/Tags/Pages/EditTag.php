<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Tags\TagResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    use ReportsDatabaseRefusals, SavesSeoMeta;

    protected static string $resource = TagResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takeSeo($data);
        $data['slug'] = UniqueSlug::for('tags', $data['slug'] ?: $data['name_ar'], $this->getRecord()->id, 'tag');

        return $data;
    }
}
