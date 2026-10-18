<?php

namespace App\Filament\Resources\MediaAssets\Pages;

use App\Filament\Resources\MediaAssets\MediaAssetResource;
use Filament\Resources\Pages\EditRecord;

class EditMediaAsset extends EditRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [MediaAssetResource::trashAction(), MediaAssetResource::purgeAction()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['focal'] = isset($data['focal_x'], $data['focal_y']) ? ['x' => (float) $data['focal_x'], 'y' => (float) $data['focal_y']] : null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $focal = $data['focal'] ?? null;
        $data['focal_x'] = isset($focal['x']) ? round(min(1, max(0, (float) $focal['x'])), 3) : null;
        $data['focal_y'] = isset($focal['y']) ? round(min(1, max(0, (float) $focal['y'])), 3) : null;
        unset($data['focal']);

        return $data;
    }
}
