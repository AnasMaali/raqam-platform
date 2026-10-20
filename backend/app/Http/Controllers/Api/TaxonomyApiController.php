<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return $s ? response()->json(['data' => $this->section($s)]) : $this->notFound();
    }
}
