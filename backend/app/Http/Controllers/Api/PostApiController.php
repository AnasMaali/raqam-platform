<?php

namespace App\Http\Controllers\Api;

use App\Content\PostPath;
use App\Models\Post;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostApiController extends ApiController
{
    public const KINDS = ['news', 'report', 'opinion', 'infographic', 'episode', 'story', 'product', 'history', 'initiative', 'roundtable', 'awareness'];

    public function index(Request $request): JsonResponse
    {
        $slug = ['nullable', 'string', 'max:200', 'regex:/^[^\s\/?#%&]+$/u'];
        $number = ['nullable', 'integer', 'min:1', 'max:32767'];
        $q = $this->query($request, [
            // One kind or several, comma-separated: /news lists news, reports, opinion and infographics together.
            'kind' => ['nullable', 'string', 'max:120', 'regex:/^('.implode('|', self::KINDS).')(,('.implode('|', self::KINDS).'))*$/'],
            'category' => $slug, 'tag' => $slug, 'section' => $slug, 'author' => $slug, 'guest' => $slug,
            'featured' => ['nullable', 'in:1'], 'breaking' => ['nullable', 'in:1'],
            // 5.5: initiatives by their state; 5.4: the history timeline, oldest era and year first.
            'initiative_status' => ['nullable', 'in:ongoing,ended'], 'order' => ['nullable', 'in:latest,timeline'],
            // The section filters (5.9): podcast season (its number), sector of a success story's or a product's company,
            // history era (its id; eras have no slug), the character of an awareness card.
            'season' => $number, 'sector' => $slug, 'era' => $number, 'character' => $slug,
        ]);

        $posts = Post::query()->live()
            ->when($q['kind'] ?? null, fn ($b, $v) => $b->whereIn('kind_code', array_unique(explode(',', $v))))
            ->when($q['category'] ?? null, fn ($b, $v) => $b->whereIn('category_id', DB::table('categories')->where('is_active', true)
                ->where(fn ($c) => $c->where('slug', $v)->orWhereIn('parent_id', DB::table('categories')->where('slug', $v)->select('id')))->select('id')))
            ->when($q['tag'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_tags')->join('tags', 'tags.id', '=', 'post_tags.tag_id')->where('tags.slug', $v)->select('post_id')))
            ->when($q['section'] ?? null, fn ($b, $v) => $b->whereIn('kind_code', DB::table('post_kinds')->join('sections', 'sections.id', '=', 'post_kinds.section_id')->where('sections.slug', $v)->where('sections.is_visible', true)->select('code')))
            ->when($q['author'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_authors')->join('authors', 'authors.id', '=', 'post_authors.author_id')->where('authors.slug', $v)->whereNull('authors.deleted_at')->select('post_id')))
            ->when($q['guest'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_guests')->join('guests', 'guests.id', '=', 'post_guests.guest_id')->where('guests.slug', $v)->whereNull('guests.deleted_at')->select('post_id')))
            ->when($q['featured'] ?? null, fn ($b) => $b->where('is_featured', true))
            ->when($q['breaking'] ?? null, fn ($b) => $b->where('breaking_until', '>', now()))
            ->when($q['initiative_status'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('initiatives')->where('status', $v)->select('post_id')))
            ->when($q['season'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('podcast_episodes')->join('podcast_seasons', 'podcast_seasons.id', '=', 'podcast_episodes.season_id')
                ->where('podcast_seasons.season_number', $v)->select('post_id')))
            ->when($q['sector'] ?? null, fn ($b, $v) => $b->whereIn('id', self::bySector('success_stories', $v)->union(self::bySector('products', $v))))
            ->when($q['era'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('history_entries')->where('era_id', $v)->select('post_id')))
            ->when($q['character'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')
                ->join('characters', 'characters.id', '=', 'character_poses.character_id')->where('characters.slug', $v)->select('post_id')))
            ->when(($q['order'] ?? 'latest') === 'timeline',
                // Items without a place on the timeline (other kinds) come after it, newest first as usual.
                // Sub-selects, not joins: the visibility rules name their columns without the table.
                fn ($b) => $b->orderByRaw('(SELECT e.sort_order FROM history_entries h JOIN eras e ON e.id = h.era_id WHERE h.post_id = posts.id) NULLS LAST')
                    ->orderByRaw('(SELECT h.year_from FROM history_entries h WHERE h.post_id = posts.id) NULLS LAST')->orderBy('published_at')->orderBy('id'),
                fn ($b) => $b->orderByDesc('published_at')->orderByDesc('id'))
            ->paginate(self::PER_PAGE, page: (int) ($q['page'] ?? 1));

        return $this->pageOf($posts, $this->presenter->summaries(collect($posts->items())), array_diff_key($q, ['page' => 1]));
    }

    public function show(int $id, ?string $slug = null): JsonResponse
    {
        $post = Post::query()->viewable()->find($id);

        if ($post === null) {
            return $this->missing($id);
        }

        if ($slug !== null && $slug !== $post->slug) {
            $path = PostPath::for($post->kind_code, $post->id, $post->slug);

            return response()->json(['path' => $path], 301)->header('Location', url('/api/v1/posts/'.$post->id.'/'.rawurlencode($post->slug)));
        }

        return response()->json(['data' => $this->presenter->detail($post)]);
    }

    /** The posts of one kind's table whose company is in the sector. */
    private static function bySector(string $table, string $sector): Builder
    {
        return DB::table($table)->join('organizations', 'organizations.id', '=', "{$table}.organization_id")
            ->join('sectors', 'sectors.id', '=', 'organizations.sector_id')->where('sectors.slug', $sector)->select("{$table}.post_id");
    }

    /** A post that is not there: gone for good (410) if it was deleted, otherwise just not found. */
    private function missing(int $id): JsonResponse
    {
        $trashed = Post::onlyTrashed()->whereKey($id)->exists();
        $purged = DB::table('redirects')->where('status_code', 410)->whereRaw('from_path ~ ?', ['^/[^/]+/'.$id.'$'])->exists();

        return $trashed || $purged ? $this->error(410, 'gone', 'هذه المادة حُذفت.') : $this->notFound();
    }
}
