<?php

namespace App\Search\Indexes;

use App\Models\Post;
use App\Search\PlainText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every kind of item (10.4). What belongs is Post::live() and nothing else: published, its time has come, not in
 * the trash, and not the card of a hidden character. Drafts, scheduled, archived and trashed items never enter.
 */
final class PostsIndex extends BaseIndex
{
    /** Meilisearch keeps the first 65,535 positions of a field; a body is cut well before that. */
    private const BODY_CHARACTERS = 60_000;

    public function key(): string
    {
        return 'posts';
    }

    protected function searchable(): array
    {
        return ['title_n', 'subtitle_n', 'tags_n', 'excerpt_n', 'people_n', 'category_n', 'body_n'];
    }

    protected function displayed(): array
    {
        return ['id', 'title_n', 'excerpt_n'];
    }

    protected function filterable(): array
    {
        return ['kind', 'section', 'categories', 'published_at'];
    }

    protected function sortable(): array
    {
        return ['published_at'];
    }

    public function belonging(): Builder
    {
        return Post::query()->live()->toBase();
    }

    public function documents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $posts = Post::query()->live()->whereIn('posts.id', $ids)
            ->get(['posts.id', 'kind_code', 'category_id', 'title', 'subtitle', 'excerpt', 'body', 'published_at']);
        if ($posts->isEmpty()) {
            return [];
        }
        $live = $posts->pluck('id')->all();

        $categories = DB::table('categories')->get(['id', 'parent_id', 'name_ar'])->keyBy('id');
        $sections = DB::table('post_kinds')->leftJoin('sections', 'sections.id', '=', 'post_kinds.section_id')->pluck('sections.key', 'post_kinds.code');
        $names = fn (Builder $q) => $q->whereIn('post_id', $live)->get()->groupBy('post_id')->map(fn ($rows) => $rows->pluck('name')->all());
        $tags = $names(DB::table('post_tags')->join('tags', 'tags.id', '=', 'post_tags.tag_id')->select('post_id', 'tags.name_ar as name'));
        $authors = $names(DB::table('post_authors')->join('authors', 'authors.id', '=', 'post_authors.author_id')->whereNull('authors.deleted_at')->select('post_id', 'authors.name'));
        $guests = $names(DB::table('post_guests')->join('guests', 'guests.id', '=', 'post_guests.guest_id')->whereNull('guests.deleted_at')->select('post_id', 'guests.name'));

        return $posts->map(function (Post $post) use ($categories, $sections, $tags, $authors, $guests) {
            $category = $post->category_id ? $categories->get($post->category_id) : null;
            $parent = $category?->parent_id ? $categories->get($category->parent_id) : null;

            return [
                'id' => $post->id,
                'kind' => $post->kind_code,
                'section' => $sections[$post->kind_code] ?? null,
                // A main category matches its subcategories too, as in the API (filter by the parent finds the child).
                'categories' => array_values(array_filter([$category?->id, $parent?->id])),
                'published_at' => $post->published_at->getTimestamp(),
                'title_n' => self::form($post->title),
                'subtitle_n' => self::form($post->subtitle),
                'excerpt_n' => self::form($post->excerpt),
                'tags_n' => self::form(...$tags->get($post->id, [])),
                'people_n' => self::form(...$authors->get($post->id, []), ...$guests->get($post->id, [])),
                'category_n' => self::form($category?->name_ar, $parent?->name_ar),
                'body_n' => self::form(mb_substr(PlainText::fromHtml($post->body), 0, self::BODY_CHARACTERS)),
            ];
        })->values()->all();
    }
}
