<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A video of the library (decision 8): a YouTube video (facts in youtube_videos) or a short uploaded clip.
 * Added once, used by any number of items and playlists (8.5). The title and description the site shows can be
 * changed here without touching YouTube (8.4).
 */
class Video extends Model
{
    use Audited, SoftDeletes;

    protected $fillable = ['display_title', 'display_description', 'poster_media_id'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'integer'];
    }

    public function youtube(): HasOne
    {
        return $this->hasOne(YoutubeVideo::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'upload_media_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'poster_media_id');
    }

    /** Search by the title the site shows or the one YouTube gave. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn (Builder $q) => $q->where('display_title', 'ilike', $like)
            ->orWhereHas('youtube', fn (Builder $y) => $y->where('source_title', 'ilike', $like)));
    }

    /**
     * Videos a visitor may see (decision 8): not in the trash, playable (an uploaded clip, or a YouTube video
     * that is still there), and in use: inside a published item or in a visible playlist. A video nobody has
     * published yet is not listed, so work in progress does not leak through the video library.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereNull('videos.deleted_at')
            ->where(fn (Builder $q) => $q->where('videos.source', 'upload')
                ->orWhereExists(fn ($y) => $y->selectRaw('1')->from('youtube_videos')->whereColumn('youtube_videos.video_id', 'videos.id')->where('youtube_videos.availability', 'ok')))
            ->where(fn (Builder $q) => $q
                ->whereExists(fn ($p) => $p->selectRaw('1')->from('playlist_videos')->join('playlists', 'playlists.id', '=', 'playlist_videos.playlist_id')
                    ->whereColumn('playlist_videos.video_id', 'videos.id')->where('playlists.is_visible', true))
                ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')
                    ->whereColumn('video_usage.video_id', 'videos.id')->where('posts.status', 'published')->where('posts.published_at', '<=', now())->whereNull('posts.deleted_at')));
    }

    /** Used in a playlist of this section, or in an item of a kind that belongs to it. */
    public function scopeInSection(Builder $query, int $sectionId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereExists(fn ($p) => $p->selectRaw('1')->from('playlist_videos')->join('playlists', 'playlists.id', '=', 'playlist_videos.playlist_id')
                ->whereColumn('playlist_videos.video_id', 'videos.id')->where('playlists.section_id', $sectionId))
            ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')->join('post_kinds', 'post_kinds.code', '=', 'posts.kind_code')
                ->whereColumn('video_usage.video_id', 'videos.id')->where('post_kinds.section_id', $sectionId)));
    }

    public function scopeInPlaylist(Builder $query, int $playlistId): Builder
    {
        return $query->whereExists(fn ($p) => $p->selectRaw('1')->from('playlist_videos')
            ->whereColumn('playlist_videos.video_id', 'videos.id')->where('playlist_videos.playlist_id', $playlistId));
    }

    public function isYoutube(): bool
    {
        return $this->source === 'youtube';
    }

    /** What the site shows: our own title when one was set, otherwise YouTube's. */
    public function title(): string
    {
        return $this->display_title ?: ($this->youtube?->source_title ?? '');
    }

    public function description(): ?string
    {
        return $this->display_description ?: $this->youtube?->source_description;
    }
}
