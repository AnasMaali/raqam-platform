<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesCharacterPoses;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Characters\CharacterResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCharacter extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesCharacterPoses, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
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

    protected function handleRecordCreation(array $data): Model
    {
        return $this->reportRefusals(function () use ($data) {
            $record = new ($this->getModel())($data);
            $record->holdWebhook = true;   // announced once in afterCreate, when the poses and SEO are saved too
            $record->save();

            return $record;
        });
    }

    protected function afterCreate(): void
    {
        $this->savePoses();
        $this->syncSeo();

        $record = $this->getRecord();
        $record->holdWebhook = false;
        $record->announceCreated();
    }
}
