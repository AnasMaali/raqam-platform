<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Awareness\CharacterLibrary;
use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesCharacterPoses;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Characters\CharacterResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;

class EditCharacter extends EditRecord
{
    use ReportsDatabaseRefusals, SavesCharacterPoses, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = CharacterResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->fillSeo($data);
        $data['poses'] = app(CharacterLibrary::class)->poses($this->getRecord());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takePoses($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('characters', $data['slug'] ?: $data['name_ar'], $this->getRecord()->id, 'character');
        $data['rive_media_id'] = ($data['rive_media_id'] ?? null) ?: null;

        return $data;
    }

    protected function afterSave(): void
    {
        $this->savePoses();
        $this->saveSeo();
    }
}
