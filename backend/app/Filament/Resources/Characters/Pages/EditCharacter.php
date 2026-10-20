<?php

namespace App\Filament\Resources\Characters\Pages;

use App\Awareness\CharacterLibrary;
use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesCharacterPoses;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Characters\CharacterResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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

    /** @var list<string> the columns this save wrote, taken before the SEO step saves the record again */
    private array $changedColumns = [];

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->holdWebhook = true;
        $record = parent::handleRecordUpdate($record, $data);
        $this->changedColumns = array_keys($record->getChanges());

        return $record;
    }

    protected function afterSave(): void
    {
        $posesChanged = $this->savePoses();
        $this->saveSeo();

        $record = $this->getRecord();
        $record->holdWebhook = false;
        $record->announce($this->changedColumns, $posesChanged);   // one webhook for the whole save
    }
}
