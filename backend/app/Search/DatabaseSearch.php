<?php

namespace App\Search;

use App\Models\Post;
use App\Models\Video;
use App\Search\Indexes\TagsIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The fallback search on PostgreSQL (10.11): used when Meilisearch is off or down, so search keeps working.
 * It is simpler than the engine: items are matched on title, subtitle and summary (the generated search_text
 * column, with its pg_trgm index), every word of the query must appear (with its synonyms as alternatives),
 * there is no typo tolerance, and relevance is pg_trgm similarity. The same visibility rules as the indexes
 * apply (Post::live(), Video::public(), active writers, tags with a live item), and the same filters.
 */
final class DatabaseSearch
{
    public function search(SearchQuery $query): SearchResults
    {
        $book = SynonymBook::forEngine();
        $alternatives = array_map(fn (string $term) => SynonymBook::expand($term, $book), $query->terms);
        $highlight = array_merge(...$alternatives);

        $posts = Post::query()->live();
        $this->matchAll($posts, 'posts.search_text', $alternatives);
        $this->filter($posts, $query);
        $total = (clone $posts)->count();

        $posts = $query->sort === 'latest'
            ? $posts->orderByDesc('published_at')->orderByDesc('posts.id')
            : $posts->orderByRaw('similarity(posts.search_text, ?) DESC', [$query->form])->orderByDesc('published_at')->orderByDesc('posts.id');
        $rows = $posts->offset($query->offset())->limit(SearchQuery::PER_PAGE)->get(['posts.id', 'title', 'excerpt']);

        $hit = fn (int $id, array $texts) => [
            'id' => $id,
            'fields' => array_map(fn (?string $t) => SearchForm::text($t), $texts),
            'matches' => array_filter(array_map(fn (?string $t) => SearchForm::of($t)->matchesOf($highlight), $texts)),
        ];

        $groups = [];
        if ($query->page === 1 && ! $query->filtered()) {
            $videoTitle = "normalize_ar(coalesce(nullif(videos.display_title, ''), youtube_videos.source_title))";
            $videos = Video::query()->public()->leftJoin('youtube_videos', 'youtube_videos.video_id', '=', 'videos.id');
            $this->matchAll($videos, DB::raw($videoTitle), $alternatives);
            $groups['videos'] = $videos->limit(SearchQuery::GROUP_LIMIT)->orderByDesc('videos.id')
                ->get(['videos.id', 'videos.display_title', 'youtube_videos.source_title'])
                ->map(fn ($v) => $hit((int) $v->id, ['title_n' => $v->display_title ?: $v->source_title]))->all();

            $people = fn (QueryBuilder $q, string $table) => $this->matchAll($q, $table.'.search_name', $alternatives)
                ->orderByRaw("similarity({$table}.search_name, ?) DESC", [$query->form])->limit(SearchQuery::GROUP_LIMIT)
                ->get([$table.'.id', $table.'.'.($table === 'tags' ? 'name_ar' : 'name').' as name'])
                ->map(fn ($r) => $hit((int) $r->id, ['name_n' => $r->name]))->all();

            $groups['authors'] = $people(DB::table('authors')->whereNull('deleted_at')->where('is_active', true), 'authors');
            $groups['guests'] = $people(DB::table('guests')->whereNull('deleted_at'), 'guests');
            $groups['tags'] = $people((new TagsIndex)->belonging(), 'tags');
        }

        return new SearchResults(SearchResults::FALLBACK, $rows->map(fn ($p) => $hit((int) $p->id, ['title_n' => $p->title, 'excerpt_n' => $p->excerpt]))->all(), $total, $groups);
    }

    /**
     * Every word must appear (as a substring of the normalised text, so «شركه» finds «الشركه»), or one of its synonyms.
     *
     * @param  list<list<string>>  $alternatives
     */
    private function matchAll(Builder|QueryBuilder $query, mixed $column, array $alternatives): Builder|QueryBuilder
    {
        foreach ($alternatives as $forms) {
            $query->where(function ($q) use ($column, $forms) {
                foreach ($forms as $form) {
                    $q->orWhere($column, 'ilike', '%'.addcslashes($form, '%_\\').'%');
                }
            });
        }

        return $query;
    }

    private function filter(Builder $posts, SearchQuery $query): void
    {
        $posts
            ->when($query->categoryId, fn ($b, $id) => $b->whereIn('category_id', DB::table('categories')->where('id', $id)->orWhere('parent_id', $id)->select('id')))
            ->when($query->sectionKey, fn ($b, $key) => $b->whereIn('kind_code', DB::table('post_kinds')->join('sections', 'sections.id', '=', 'post_kinds.section_id')->where('sections.key', $key)->select('code')))
            ->when($query->kind, fn ($b, $kind) => $b->where('kind_code', $kind))
            ->when($query->from, fn ($b, $from) => $b->where('published_at', '>=', $from))
            ->when($query->to, fn ($b, $to) => $b->where('published_at', '<=', $to));
    }
}
