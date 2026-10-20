<?php

namespace App\Filament\Concerns;

use App\Video\VideoLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** For the playlist pages: the ordered videos and the podcast season are not columns, so they are set aside and saved after the record. */
trait SavesPlaylistVideos
{
    /** @var list<int|string> */
    private array $playlistVideos = [];

    private ?int $playlistSeason = null;

    protected function takePlaylistParts(array $data, ?int $ownId = null): array
    {
        $this->playlistVideos = collect($data['videos'] ?? [])->pluck('video_id')->filter()->values()->all();
        $season = $data['season_id'] ?? null;
        $this->playlistSeason = is_scalar($season) && ctype_digit((string) $season) ? (int) $season : null;
        unset($data['videos'], $data['season_id']);

        // Checked before anything is written, so a refusal leaves no half-made playlist behind.
        if ($this->playlistSeason !== null) {
            $season = DB::table('podcast_seasons')->where('id', $this->playlistSeason)->first();
            if ($season === null || ($season->playlist_id !== null && (int) $season->playlist_id !== $ownId)) {
                throw ValidationException::withMessages(['data.season_id' => 'هذا الموسم غير موجود أو مرتبط بقائمة تشغيل أخرى.']);
            }
        }

        return $data;
    }

    protected function savePlaylistParts(Model $playlist): void
    {
        app(VideoLibrary::class)->setPlaylistVideos($playlist->id, $this->playlistVideos);

        DB::table('podcast_seasons')->where('playlist_id', $playlist->id)->update(['playlist_id' => null]);
        if ($this->playlistSeason !== null) {
            DB::table('podcast_seasons')->where('id', $this->playlistSeason)->update(['playlist_id' => $playlist->id]);
        }
    }
}
