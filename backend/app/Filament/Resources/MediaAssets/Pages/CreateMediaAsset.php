<?php

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Media\MediaRejected;
use App\Media\MediaUploader;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $file = $data['file'];

        try {
            $upload = app(MediaUploader::class)->store(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                [
                    'alt_text' => $data['alt_text'] ?? null,
                    'caption' => $data['caption'] ?? null,
                    'credit' => $data['credit'] ?? null,
                    'visibility' => $data['visibility'] ?? 'public',
                ],
                Filament::auth()->id(),
            );
        } catch (MediaRejected $e) {
            throw ValidationException::withMessages(["data.{$e->field}" => $e->getMessage()]);
        }

        if ($upload->duplicate) {
            Notification::make()->warning()->title('هذا الملف موجود مسبقاً في المكتبة، لم يُرفع مرة ثانية.')->send();
        }

        return $upload->asset;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم رفع الملف.';
    }
}
