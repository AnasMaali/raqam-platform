<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Filament\Resources\Synonyms\SynonymGroupResource;
use App\Models\SynonymGroup;
use App\Search\SynonymLibrary;
use App\Search\SynonymRejected;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSynonymGroup extends EditRecord
{
    protected static string $resource = SynonymGroupResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SynonymGroup $group */
        $group = $this->getRecord();

        return ['terms' => $group->terms()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(SynonymLibrary::class)->save($record->id, (array) ($data['terms'] ?? []), auth()->id());
        } catch (SynonymRejected $e) {
            throw ValidationException::withMessages(['data.terms' => $e->getMessage()]);
        }

        return $record;
    }
}
