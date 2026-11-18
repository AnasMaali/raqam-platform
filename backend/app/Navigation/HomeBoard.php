<?php

namespace App\Navigation;

use App\Analytics\MostRead;
use App\Api\Presenter;
use App\Models\Post;
use App\Models\Video;
use App\Video\VideoView;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The home page for the site (21.11, GET /api/v1/home): the visible blocks in the admin's order, each with what it shows
 * now. Posts are live only; a block whose category, section or playlist is hidden, or that has nothing to show, is left
 * out rather than drawn empty. Currencies, weather and ads keep their own endpoints (their own caches and counting):
 * the block says where to read them.
 */
final class HomeBoard
{
    /** What the blocks of one answer share, read once in blocks(): the active categories, the visible sections and their kinds. */
    private Collection $categories;

    private Collection $sections;

    private Collection $kinds;

    public function __construct(private Presenter $presenter, private VideoView $videos, private MostRead $mostRead) {}

    /**
     * @return list<array<string, mixed>>
     *
     * In two steps (QA-5): each block takes its posts (one query of its own), then the posts of every block are drawn
     * together in one summaries() call, and the blocks' categories and sections are read once for all of them: a block
     * more costs its own query, not the dozen a list of posts needs.
     */
    public function blocks(): array
    {
        $rows = DB::table('home_blocks')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $this->categories = $rows->contains('block_type', 'category') ? DB::table('categories')->where('is_active', true)->get(['id', 'parent_id', 'name_ar', 'slug']) : collect();
        $this->sections = $rows->contains('block_type', 'section')
            ? DB::table('sections')->where('is_visible', true)->get(['id', 'key', 'name_ar', 'slug'])->keyBy('id') : collect();
        $this->kinds = $rows->contains('block_type', 'section') ? DB::table('post_kinds')->whereNotNull('section_id')->get(['code', 'section_id'])->groupBy('section_id') : collect();

        $pending = [];
        foreach ($rows as $row) {
            $limit = (int) ($row->item_limit ?? HomeLayout::DEFAULT_LIMIT[$row->block_type] ?? 6);
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
                $pending[] = [$row, $filled];
            }
        }

        // The posts of every block, drawn once (a post in two blocks is drawn once too).
        $posts = collect($pending)->flatMap(fn ($p) => $p[1]['posts'] ?? [])->unique('id')->values();
        $drawn = collect($this->presenter->summaries($posts))->keyBy('id');

        $out = [];
        foreach ($pending as [$row, $filled]) {
            if (isset($filled['posts'])) {
                $filled['posts'] = $filled['posts']->map(fn (Post $p) => $drawn[$p->id])->values()->all();
            }
            $block = ['id' => (int) $row->id, 'type' => $row->block_type, 'title' => $row->title_ar];
            $block['title'] ??= $filled['title'] ?? HomeLayout::TYPES[$row->block_type];
            unset($filled['title']);
            $out[] = $block + $filled;
        }

        return $out;
    }

    /** The featured posts first (the newest featured is the main story), then the newest others. */
    private function hero(int $limit)
    {
        return Post::query()->live()->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** A block's posts, kept as models until every block has taken its own (blocks() draws them all at once). */
    private function posts($posts): ?array
    {
        return $posts->isEmpty() ? null : ['posts' => $posts->values()];
    }

    private function category(int $id, int $limit): ?array
    {
        $category = $this->categories->firstWhere('id', $id);
        if ($category === null) {
            return null;
        }
        $ids = $this->categories->filter(fn ($c) => (int) $c->id === $id || (int) $c->parent_id === $id)->pluck('id');
        $posts = $this->posts(Post::query()->live()->whereIn('category_id', $ids)->orderByDesc('published_at')->orderByDesc('id')->limit($limit)->get());

        return $posts === null ? null : ['title' => $category->name_ar, 'category' => ['id' => (int) $category->id, 'name' => $category->name_ar, 'slug' => $category->slug]] + $posts;
    }

    private function section(int $id, int $limit): ?array
    {
        $section = $this->sections->get($id);
        if ($section === null) {
            return null;
        }
        $kinds = ($this->kinds->get($id) ?? collect())->pluck('code');
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
        $this->videos->prime($videos);

        return $videos->isEmpty() ? null : ['videos' => $videos->map(fn (Video $v) => $this->videos->payload($v))->values()->all()];
    }
}
