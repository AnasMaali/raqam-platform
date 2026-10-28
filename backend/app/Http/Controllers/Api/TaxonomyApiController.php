<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaxonomyApiController extends ApiController
{
    public function categories(): JsonResponse
    {
        $all = DB::table('categories')->where('is_active', true)->orderBy('sort_order')->get();

        return response()->json(['data' => $all->whereNull('parent_id')->map(fn ($c) => $this->category($c, $all))->values()->all()]);
    }

    public function category(string|object $slug, $all = null): JsonResponse|array
    {
        if (is_object($slug)) {
            $kids = $all->where('parent_id', $slug->id)->map(fn ($k) => ['id' => (int) $k->id, 'name' => $k->name_ar, 'slug' => $k->slug, 'description' => $k->description])->values()->all();

            return ['id' => (int) $slug->id, 'name' => $slug->name_ar, 'slug' => $slug->slug, 'description' => $slug->description, 'is_highlighted' => (bool) $slug->is_highlighted, 'children' => $kids];
        }

        $row = DB::table('categories')->where('slug', $slug)->where('is_active', true)->first();
        if (! $row) {
            return $this->notFound();
        }
        $all = DB::table('categories')->where('is_active', true)->orderBy('sort_order')->get();
        $data = $this->category($row, $all);
        if ($row->parent_id && ($p = $all->firstWhere('id', $row->parent_id))) {
            $data['parent'] = ['id' => (int) $p->id, 'name' => $p->name_ar, 'slug' => $p->slug];
        }

        return response()->json(['data' => $data]);
    }

    public function tags(Request $request): JsonResponse
    {
        $page = $this->page($request);
        $live = DB::table('post_tags')->join('posts', 'posts.id', '=', 'post_tags.post_id')->where('posts.status', 'published')->where('posts.published_at', '<=', now())->whereNull('posts.deleted_at')->whereNotIn('posts.id', Post::hiddenCharacterCards())
            ->groupBy('post_tags.tag_id')->selectRaw('post_tags.tag_id, count(*) as n');
        $tags = DB::table('tags')->joinSub($live, 'c', 'c.tag_id', '=', 'tags.id')->orderByDesc('c.n')->orderBy('tags.name_ar')
            ->select('tags.id', 'tags.name_ar', 'tags.slug', 'c.n')->paginate(self::PER_PAGE, page: $page);

        return $this->pageOf($tags, collect($tags->items())->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name_ar, 'slug' => $t->slug, 'posts_count' => (int) $t->n])->all());
    }

    public function tag(string $slug): JsonResponse
    {
        $t = DB::table('tags')->where('slug', $slug)->first();
        if (! $t) {
            return $this->notFound();
        }
        $n = DB::table('post_tags')->join('posts', 'posts.id', '=', 'post_tags.post_id')->where('tag_id', $t->id)->where('posts.status', 'published')->where('posts.published_at', '<=', now())->whereNull('posts.deleted_at')->whereNotIn('posts.id', Post::hiddenCharacterCards())->count();

        return $n === 0 ? $this->notFound() : response()->json(['data' => ['id' => (int) $t->id, 'name' => $t->name_ar, 'slug' => $t->slug, 'posts_count' => $n]]);
    }

    public function sections(): JsonResponse
    {
        return response()->json(['data' => DB::table('sections')->where('is_visible', true)->orderBy('sort_order')->get()->map(fn ($s) => $this->section($s))->all()]);
    }

    public function section(string|object $slug): JsonResponse|array
    {
        if (is_object($slug)) {
            return ['id' => (int) $slug->id, 'key' => $slug->key, 'name' => $slug->name_ar, 'slug' => $slug->slug, 'description' => $slug->description, 'cover' => $this->presenter->media($slug->cover_media_id)];
        }
        $s = DB::table('sections')->where('slug', $slug)->where('is_visible', true)->first();

        return $s ? response()->json(['data' => $this->section($s) + ['filters' => $this->filters($s->key)]]) : $this->notFound();
    }

    /** The eras that have a live history item, in the admin's order (5.4: the timeline and its filter). */
    public function eras(): JsonResponse
    {
        return response()->json(['data' => $this->eraRows()->map(fn ($e) => [
            'id' => (int) $e->id, 'name' => $e->name_ar, 'year_from' => $e->year_from === null ? null : (int) $e->year_from,
            'year_to' => $e->year_to === null ? null : (int) $e->year_to, 'posts_count' => (int) $e->n,
        ])->all()]);
    }

    /**
     * The filters of a section page (5.9, first version): the `/posts` parameter, its label and the values that have live
     * items, each with its count. The round table has none, and no section filters by city.
     *
     * @return list<array{param: string, name: string, options: list<array{value: string, name: string, posts_count: int}>}>
     */
    private function filters(string $key): array
    {
        $option = fn (string|int $value, string $name, int $n) => ['value' => (string) $value, 'name' => $name, 'posts_count' => $n];
        $filter = fn (string $param, string $name, $options) => [['param' => $param, 'name' => $name, 'options' => collect($options)->values()->all()]];
        $live = fn () => Post::query()->live()->select('id');

        return match ($key) {
            'podcast' => $filter('season', 'الموسم', DB::table('podcast_episodes')->join('podcast_seasons', 'podcast_seasons.id', '=', 'podcast_episodes.season_id')
                ->whereIn('post_id', $live())->groupBy('season_number', 'title_ar')->orderBy('season_number')->selectRaw('season_number, title_ar, count(*) AS n')->get()
                ->map(fn ($r) => $option($r->season_number, 'الموسم '.$r->season_number.($r->title_ar ? ': '.$r->title_ar : ''), (int) $r->n))),
            'success_stories', 'made_in_nablus' => $filter('sector', 'القطاع', DB::table($key === 'made_in_nablus' ? 'products AS t' : 'success_stories AS t')
                ->join('organizations', 'organizations.id', '=', 't.organization_id')->join('sectors', 'sectors.id', '=', 'organizations.sector_id')
                ->whereIn('t.post_id', $live())->groupBy('sectors.id', 'sectors.slug', 'sectors.name_ar', 'sectors.sort_order')->orderBy('sectors.sort_order')->orderBy('sectors.name_ar')
                ->selectRaw('sectors.slug, sectors.name_ar, count(*) AS n')->get()->map(fn ($r) => $option($r->slug, $r->name_ar, (int) $r->n))),
            'nablus_history' => $filter('era', 'الحقبة', $this->eraRows()->map(fn ($e) => $option($e->id, $e->name_ar, (int) $e->n))),
            'initiatives' => $filter('initiative_status', 'الحالة', DB::table('initiatives')->whereIn('post_id', $live())->groupBy('status')->selectRaw('status, count(*) AS n')
                ->pluck('n', 'status')->sortKeysUsing(fn ($a, $b) => $a === 'ongoing' ? -1 : ($b === 'ongoing' ? 1 : 0))
                ->map(fn ($n, $status) => $option($status, $status === 'ongoing' ? 'جارية' : 'منتهية', (int) $n))),
            'awareness' => $filter('character', 'الشخصية', DB::table('awareness_cards')->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')
                ->join('characters', 'characters.id', '=', 'character_poses.character_id')->whereIn('post_id', $live())
                ->groupBy('characters.id', 'characters.slug', 'characters.name_ar', 'characters.sort_order')->orderBy('characters.sort_order')->orderBy('characters.id')
                ->selectRaw('characters.slug, characters.name_ar, count(*) AS n')->get()->map(fn ($r) => $option($r->slug, $r->name_ar, (int) $r->n))),
            default => [],
        };
    }

    /** @return Collection<int, object> eras with their number of live history items (n), only those that have one */
    private function eraRows(): Collection
    {
        $counts = DB::table('history_entries')->whereIn('post_id', Post::query()->live()->select('id'))->groupBy('era_id')->selectRaw('era_id, count(*) AS n');

        return DB::table('eras')->joinSub($counts, 'c', 'c.era_id', '=', 'eras.id')->orderBy('eras.sort_order')->orderBy('eras.id')
            ->get(['eras.id', 'eras.name_ar', 'eras.year_from', 'eras.year_to', 'c.n']);
    }
}
