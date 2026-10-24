<?php

namespace App\Navigation;

use App\Analytics\MostRead;
use App\Api\Presenter;
use App\Models\Post;
use App\Models\Video;
use App\Video\VideoView;
use Illuminate\Support\Facades\DB;

/**
 * The home page for the site (21.11, GET /api/v1/home): the visible blocks in the admin's order, each with what it shows
 * now. Posts are live only; a block whose category, section or playlist is hidden, or that has nothing to show, is left
 * out rather than drawn empty. Currencies, weather and ads keep their own endpoints (their own caches and counting):
 * the block says where to read them.
 */
final class HomeBoard
{
    public function __construct(private Presenter $presenter, private VideoView $videos, private MostRead $mostRead) {}

    /** @return list<array<string, mixed>> */
    public function blocks(): array
    {
        $out = [];
        foreach (DB::table('home_blocks')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $row) {
            $limit = (int) ($row->item_limit ?? HomeLayout::DEFAULT_LIMIT[$row->block_type] ?? 6);
            $block = ['id' => (int) $row->id, 'type' => $row->block_type, 'title' => $row->title_ar];
            $filled = match ($row->block_type) {
                'hero' => $this->posts($this->hero($limit)),
                'breaking' => $this->posts(Post::query()->live()->where('breaking_until', '>', now())->orderByDesc('published_at')->limit($limit)->get()),
                'latest' => $this->posts(Post::query()->live()->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get()),
                'category' => $this->category((int) $row->category_id, $limit),
                'section' => $this->section((int) $row->section_id, $limit),
                'awareness' => $this->posts(Post::query()->live()->where('kind_code', 'awareness')->orderByDesc('published_at')->limit($limit)->get()),
                'most_read' => $this->posts($this->mostRead->for('day', min($limit, MostRead::MAX))['posts']),
                'videos' => $this->videoList(Video::query()->public()->with(['youtube', 'upload', 'poster'])->orderByDesc('videos.id')->limit($limit)->get()),
                'playlist' => $this->playlist((int) $row->playlist_id, $limit),
                'markets' => ['sources' => ['currencies' => '/api/v1/currencies/ticker', 'weather' => '/api/v1/weather/widget']],
                'ad' => ['placement' => $row->placement_code, 'sources' => ['ads' => '/api/v1/ads']],
                'newsletter' => ['sources' => ['subscribe' => '/api/v1/newsletter/subscribe']],
                default => null,
            };
            if ($filled !== null) {
                $block['title'] ??= $filled['title'] ?? HomeLayout::TYPES[$row->block_type];
                unset($filled['title']);
                $out[] = $block + $filled;
            }
        }

        return $out;
    }

    /** The featured posts first (the newest featured is the main story), then the newest others. */
    private function hero(int $limit)
    {
        return Post::query()->live()->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    private function posts($posts): ?array
    {
        return $posts->isEmpty() ? null : ['posts' => $this->presenter->summaries($posts->values())];
    }

    private function category(int $id, int $limit): ?array
    {
        $category = DB::table('categories')->where('id', $id)->where('is_active', true)->first(['id', 'name_ar', 'slug']);
        if ($category === null) {
            return null;
        }
        $ids = DB::table('categories')->where('is_active', true)->where(fn ($q) => $q->where('id', $id)->orWhere('parent_id', $id))->pluck('id');
        $posts = $this->posts(Post::query()->live()->whereIn('category_id', $ids)->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get());

        return $posts === null ? null : ['title' => $category->name_ar, 'category' => ['id' => (int) $category->id, 'name' => $category->name_ar, 'slug' => $category->slug]] + $posts;
    }

    private function section(int $id, int $limit): ?array
    {
        $section = DB::table('sections')->where('id', $id)->where('is_visible', true)->first(['id', 'key', 'name_ar', 'slug']);
        if ($section === null) {
            return null;
        }
        $kinds = DB::table('post_kinds')->where('section_id', $id)->pluck('code');
        $posts = $this->posts(Post::query()->live()->whereIn('kind_code', $kinds)->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get());

        return $posts === null ? null : ['title' => $section->name_ar, 'section' => ['key' => $section->key, 'name' => $section->name_ar, 'slug' => $section->slug]] + $posts;
    }

    private function playlist(int $id, int $limit): ?array
    {
        $playlist = DB::table('playlists')->where('id', $id)->where('is_visible', true)->first(['id', 'name_ar', 'slug']);
        if ($playlist === null) {
            return null;
        }
        $order = DB::table('playlist_videos')->select('sort_order')->whereColumn('playlist_videos.video_id', 'videos.id')->where('playlist_videos.playlist_id', $id)->limit(1);
        $videos = $this->videoList(Video::query()->public()->inPlaylist($id)->with(['youtube', 'upload', 'poster'])->orderBy($order)->orderBy('videos.id')->limit($limit)->get());

        return $videos === null ? null : ['title' => $playlist->name_ar, 'playlist' => ['name' => $playlist->name_ar, 'slug' => $playlist->slug]] + $videos;
    }

    private function videoList($videos): ?array
    {
        return $videos->isEmpty() ? null : ['videos' => $videos->map(fn (Video $v) => $this->videos->payload($v))->values()->all()];
    }
}
