<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoResource;
use App\Video\VideoLibrary;
use App\Video\VideoRejected;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditVideo extends EditRecord
{
    protected static string $resource = VideoResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['chapters'] = app(VideoLibrary::class)->chapters($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $library = app(VideoLibrary::class);

        try {
            DB::transaction(function () use ($library, $record, $data) {
                $library->updateDisplay($record, $data['display_title'] ?? null, $data['display_description'] ?? null, ($data['poster_media_id'] ?? null) ?: null);
                $library->syncChapters($record, $data['chapters'] ?? []);
            });
        } catch (VideoRejected $e) {
            throw ValidationException::withMessages(["data.{$e->field}" => $e->getMessage()]);
        }

        return $record->refresh();
    }
}
