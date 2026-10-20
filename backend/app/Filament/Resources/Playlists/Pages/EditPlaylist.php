<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesPlaylistVideos;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Support\UniqueSlug;
use App\Video\VideoLibrary;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPlaylist extends EditRecord
{
    use ReportsDatabaseRefusals, SavesPlaylistVideos, SavesSeoMeta {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = PlaylistResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->fillSeo($data);
        $data['videos'] = array_map(fn ($id) => ['video_id' => $id], app(VideoLibrary::class)->playlistVideoIds($this->getRecord()->id));
        $data['season_id'] = DB::table('podcast_seasons')->where('playlist_id', $this->getRecord()->id)->value('id');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takePlaylistParts($this->takeSeo($data), $this->getRecord()->id);
        $data['slug'] = UniqueSlug::for('playlists', $data['slug'] ?: $data['name_ar'], $this->getRecord()->id, 'playlist');

        return $data;
    }

    protected function afterSave(): void
    {
        $this->savePlaylistParts($this->getRecord());
        $this->saveSeo();
    }
}
