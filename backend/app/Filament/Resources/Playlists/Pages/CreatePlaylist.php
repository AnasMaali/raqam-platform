<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPlaylistVideos;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylist extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesPlaylistVideos, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterCreate as private saveSeo;
    }

    protected static string $resource = PlaylistResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takePlaylistParts($this->takeSeo($data));
        $data['slug'] = UniqueSlug::for('playlists', $data['slug'] ?: $data['name_ar'], null, 'playlist');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->savePlaylistParts($this->getRecord());
        $this->saveSeo();
    }
}
