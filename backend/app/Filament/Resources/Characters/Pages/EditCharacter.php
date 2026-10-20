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
        $record = $this->reportRefusals(fn () => parent::handleRecordUpdate($record, $data));
        $this->changedColumns = array_keys($record->getChanges());

        return $record;
    }

    protected function afterSave(): void
    {
        $posesChanged = $this->savePoses();
        $seoChanged = $this->syncSeo();

        $record = $this->getRecord();
        $record->holdWebhook = false;
        $record->announce($this->changedColumns, $posesChanged || $seoChanged);   // one webhook for the whole save
    }
}
