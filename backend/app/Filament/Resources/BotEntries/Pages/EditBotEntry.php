<?php

namespace App\Filament\Resources\BotEntries\Pages;

use App\Chat\BotLibrary;
use App\Filament\Resources\BotEntries\BotEntryResource;
use App\Models\BotEntry;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBotEntry extends EditRecord
{
    protected static string $resource = BotEntryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var BotEntry $entry */
        $entry = $this->getRecord();

        return BotEntryResource::formData($entry);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(BotLibrary::class)->save((int) $record->getKey(), $data, auth()->id());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $f) => ['data.'.$f => $m])->all());
        }

        return $record->refresh();
    }
}
