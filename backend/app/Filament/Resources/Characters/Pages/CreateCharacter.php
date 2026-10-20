<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesCharacterPoses;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Characters\CharacterResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;

class CreateCharacter extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesCharacterPoses, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterCreate as private saveSeo;
    }

    protected static string $resource = CharacterResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takePoses($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('characters', $data['slug'] ?: $data['name_ar'], null, 'character');
        $data['rive_media_id'] = ($data['rive_media_id'] ?? null) ?: null;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->savePoses();
        $this->saveSeo();
    }
}
