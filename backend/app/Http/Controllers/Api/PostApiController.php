<?php

namespace App\Http\Controllers\Api;

use App\Content\PostPath;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostApiController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $slug = ['nullable', 'string', 'max:200', 'regex:/^[^\s\/?#%&]+$/u'];
        $q = $this->query($request, [
            'kind' => ['nullable', 'in:news,report,opinion,infographic,episode,story,product,history,initiative,roundtable,awareness'],
            'category' => $slug, 'tag' => $slug, 'section' => $slug, 'author' => $slug, 'guest' => $slug,
            'featured' => ['nullable', 'in:1'], 'breaking' => ['nullable', 'in:1'],
        ]);

        $posts = Post::query()->live()
            ->when($q['kind'] ?? null, fn ($b, $v) => $b->where('kind_code', $v))
            ->when($q['category'] ?? null, fn ($b, $v) => $b->whereIn('category_id', DB::table('categories')->where('is_active', true)
                ->where(fn ($c) => $c->where('slug', $v)->orWhereIn('parent_id', DB::table('categories')->where('slug', $v)->select('id')))->select('id')))
            ->when($q['tag'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_tags')->join('tags', 'tags.id', '=', 'post_tags.tag_id')->where('tags.slug', $v)->select('post_id')))
            ->when($q['section'] ?? null, fn ($b, $v) => $b->whereIn('kind_code', DB::table('post_kinds')->join('sections', 'sections.id', '=', 'post_kinds.section_id')->where('sections.slug', $v)->where('sections.is_visible', true)->select('code')))
            ->when($q['author'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_authors')->join('authors', 'authors.id', '=', 'post_authors.author_id')->where('authors.slug', $v)->whereNull('authors.deleted_at')->select('post_id')))
            ->when($q['guest'] ?? null, fn ($b, $v) => $b->whereIn('id', DB::table('post_guests')->join('guests', 'guests.id', '=', 'post_guests.guest_id')->where('guests.slug', $v)->whereNull('guests.deleted_at')->select('post_id')))
            ->when($q['featured'] ?? null, fn ($b) => $b->where('is_featured', true))
            ->when($q['breaking'] ?? null, fn ($b) => $b->where('breaking_until', '>', now()))
            ->orderByDesc('published_at')->orderByDesc('id')
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

    /** A post that is not there: gone for good (410) if it was deleted, otherwise just not found. */
    private function missing(int $id): JsonResponse
    {
        $trashed = Post::onlyTrashed()->whereKey($id)->exists();
        $purged = DB::table('redirects')->where('status_code', 410)->whereRaw('from_path ~ ?', ['^/[^/]+/'.$id.'$'])->exists();

        return $trashed || $purged ? $this->error(410, 'gone', 'هذه المادة حُذفت.') : $this->notFound();
    }
}
