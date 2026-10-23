<?php

namespace App\Filament\Resources\BotEntries\Pages;

use App\Chat\BotLibrary;
use App\Filament\Resources\BotEntries\BotEntryResource;
use App\Models\BotEntry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateBotEntry extends CreateRecord
{
    protected static string $resource = BotEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return BotEntry::query()->findOrFail(app(BotLibrary::class)->save(null, $data, auth()->id()));
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $f) => ['data.'.$f => $m])->all());
        }
    }
}
