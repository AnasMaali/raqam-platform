<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Tags\TagResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesSeoMeta;

    protected static string $resource = TagResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takeSeo($data);
        $data['slug'] = UniqueSlug::for('tags', $data['slug'] ?: $data['name_ar'], null, 'tag');

        return $data;
    }
}
