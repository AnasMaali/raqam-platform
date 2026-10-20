<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Content\PostPath;
use App\Models\Post;
use App\Models\Video;
use App\Video\VideoView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Videos, playlists and the data for Google's video results (decisions 8.9, 8.10, 8.13). Only what a visitor may see. */
class VideoApiController extends ApiController
{
    private const WITH = ['youtube', 'upload', 'poster'];

    /** Most a playlist or the sitemap hands out in one answer. */
    private const PLAYLIST_LIMIT = 200;

    private const SITEMAP_LIMIT = 5000;

    public function __construct(Presenter $presenter, private VideoView $view)
    {
        parent::__construct($presenter);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->query($request, ['section' => ['nullable', 'string', 'max:80'], 'playlist' => ['nullable', 'string', 'max:200']]);
        $videos = Video::query()->public()->with(self::WITH);

        if (isset($q['section'])) {
            $id = DB::table('sections')->where('slug', $q['section'])->where('is_visible', true)->value('id');
            $id === null ? $videos->whereRaw('false') : $videos->inSection((int) $id);
        }
        if (isset($q['playlist'])) {
            $id = DB::table('playlists')->where('slug', $q['playlist'])->where('is_visible', true)->value('id');
            $id === null ? $videos->whereRaw('false') : $videos->inPlaylist((int) $id);
        }

        $p = $videos->orderByDesc('videos.id')->paginate(self::PER_PAGE, page: $this->page($request));

        return $this->pageOf($p, collect($p->items())->map(fn (Video $v) => $this->view->payload($v))->all(), array_filter(['section' => $q['section'] ?? null, 'playlist' => $q['playlist'] ?? null]));
    }

    public function show(string $id): JsonResponse
    {
        $video = Video::query()->public()->with(self::WITH)->find((int) $id);

        return $video ? response()->json(['data' => $this->view->payload($video)]) : $this->notFound();
    }

    public function playlists(Request $request): JsonResponse
    {
        $p = DB::table('playlists')->where('is_visible', true)->orderBy('sort_order')->orderBy('id')->paginate(self::PER_PAGE, page: $this->page($request));
        $this->presenter->preload(collect($p->items())->pluck('cover_media_id'));

        return $this->pageOf($p, collect($p->items())->map(fn ($row) => $this->playlist($row))->all());
    }

    public function playlistShow(string $slug): JsonResponse
    {
        $row = DB::table('playlists')->where('is_visible', true)->where('slug', $slug)->first();
        if ($row === null) {
            return $this->notFound();
        }

        $videos = Video::query()->public()->with(self::WITH)
            ->join('playlist_videos', 'playlist_videos.video_id', '=', 'videos.id')->where('playlist_videos.playlist_id', $row->id)
            ->orderBy('playlist_videos.sort_order')->select('videos.*')->limit(self::PLAYLIST_LIMIT)->get();

        return response()->json(['data' => $this->playlist($row) + ['videos' => $videos->map(fn (Video $v) => $this->view->payload($v))->all()]]);
    }

    /** @return array<string, mixed> */
    private function playlist(object $row): array
    {
        $section = $row->section_id ? DB::table('sections')->where('id', $row->section_id)->first() : null;
        $count = Video::query()->public()->inPlaylist((int) $row->id)->count();
        $season = DB::table('podcast_seasons')->where('playlist_id', $row->id)->first();
        $seo = $row->seo_meta_id ? DB::table('seo_meta')->where('id', $row->seo_meta_id)->first() : null;

        return [
            'id' => (int) $row->id, 'name' => $row->name_ar, 'slug' => $row->slug, 'description' => $row->description,
            'cover' => $this->presenter->media($row->cover_media_id),
            'section' => $section ? ['id' => (int) $section->id, 'name' => $section->name_ar, 'slug' => $section->slug] : null,
            'season_number' => $season ? (int) $season->season_number : null,
            'videos_count' => $count,
            'seo' => $this->presenter->seo($seo, $row->name_ar, $row->description, $row->cover_media_id),
        ];
    }

    /**
     * What a video sitemap needs (8.13): for every page that shows a video, the video's own facts. The website
     * turns this into the XML; the page is the video's library page or the published item that uses it.
     */
    public function sitemap(): JsonResponse
    {
        $entries = [];
        $videos = Video::query()->public()->with(self::WITH)->orderBy('videos.id')->limit(self::SITEMAP_LIMIT)->get()->keyBy('id');

        foreach ($videos as $video) {
            $entries[] = ['page_path' => '/video/'.$video->id, 'video' => $this->sitemapVideo($video)];
        }

        $uses = DB::table('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')->whereIn('video_usage.video_id', $videos->keys())
            ->where('posts.status', 'published')->where('posts.published_at', '<=', now())->whereNull('posts.deleted_at')->whereNotIn('posts.id', Post::hiddenCharacterCards())
            ->distinct()->orderBy('posts.id')->get(['video_usage.video_id', 'posts.id', 'posts.kind_code', 'posts.slug']);
        foreach ($uses as $use) {
            $entries[] = ['page_path' => PostPath::for($use->kind_code, (int) $use->id, $use->slug), 'video' => $this->sitemapVideo($videos[$use->video_id])];
        }

        return response()->json(['data' => $entries]);
    }

    /** @return array<string, mixed> */
    private function sitemapVideo(Video $video): array
    {
        $p = $this->view->payload($video);

        return [
            'id' => $video->id, 'title' => $p['title'], 'description' => $p['description'] ?: $p['title'], 'thumbnail_url' => $p['thumbnail_url'],
            'content_url' => $p['upload']['url'] ?? null, 'player_url' => $p['youtube']['embed_url'] ?? null,
            'duration_seconds' => $p['duration_seconds'], 'publication_date' => $p['published_at'],
        ];
    }
}
