<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoResource;
use App\Video\VideoLibrary;
use App\Video\VideoRejected;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateVideo extends CreateRecord
{
    protected static string $resource = VideoResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $library = app(VideoLibrary::class);
        $userId = Filament::auth()->id();

        try {
            if (($data['source'] ?? 'youtube') === 'upload') {
                $file = $data['file'];

                return $library->addUpload(
                    $file->getRealPath(), $file->getClientOriginalName(), (string) $data['upload_title'], $data['upload_description'] ?? null,
                    ($data['upload_poster_media_id'] ?? null) ?: null, $userId,
                );
            }

            return $library->addYoutube((string) $data['url'], ['title' => $data['manual_title'] ?? null, 'duration' => $data['manual_duration'] ?? null], $userId);
        } catch (VideoRejected $e) {
            // The form opens its hand-entry fields when YouTube could not be asked.
            $e->manualEntry && $this->data['manual'] = true;
            $field = match ($e->field) {
                'url', 'file' => $e->field,
                // The reason is shown under the link, where the person is looking; the hand-entry fields open beside it.
                'title' => $e->manualEntry ? 'url' : 'manual_title',
                'duration' => 'manual_duration',
                'poster_media_id' => 'upload_poster_media_id',
                default => 'url',
            };

            throw ValidationException::withMessages(["data.{$field}" => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'أُضيف الفيديو.';
    }
}
