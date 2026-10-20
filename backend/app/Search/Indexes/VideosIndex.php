<?php

namespace App\Search\Indexes;

use App\Models\Video;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Public videos only (Video::public(): playable, and inside a live item or a visible playlist). */
final class VideosIndex extends BaseIndex
{
    public function key(): string
    {
        return 'videos';
    }

    protected function searchable(): array
    {
        return ['title_n', 'description_n'];
    }

    public function belonging(): Builder
    {
        return Video::query()->public()->toBase();
    }

    public function documents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Video::query()->public()->whereIn('videos.id', $ids)
            ->leftJoin('youtube_videos', 'youtube_videos.video_id', '=', 'videos.id')
            ->get(['videos.id', 'videos.display_title', 'videos.display_description', DB::raw('youtube_videos.source_title'), DB::raw('youtube_videos.source_description')])
            ->map(fn ($v) => [
                'id' => (int) $v->id,
                // What the site shows: our own title and description when set, otherwise YouTube's (8.4).
                'title_n' => self::form($v->display_title ?: $v->source_title),
                'description_n' => self::form(mb_substr((string) ($v->display_description ?: $v->source_description), 0, 5000)),
            ])->values()->all();
    }
}
