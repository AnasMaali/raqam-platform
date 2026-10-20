<?php

namespace App\Search;

use App\Models\Post;
use App\Search\Indexes\TagsIndex;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a search that found nothing offers instead (10.10): words close to the query, the latest news, and
 * what is read most this week. Close words come from things that lead somewhere: tag names (a tag with a live
 * item) and terms other visitors searched that did find results; pg_trgm similarity decides "close".
 */
final class NoResults
{
    public const SUGGESTIONS = 5;

    public const ITEMS = 6;

    private const SIMILARITY = 0.3;

    /** @return list<string> */
    public function suggestions(SearchQuery $query): array
    {
        $normalised = ArabicNormalizer::normalize($query->text);
        if ($normalised === '') {
            return [];
        }

        $tags = (new TagsIndex)->belonging()
            ->selectRaw('tags.name_ar AS word, similarity(tags.search_name, ?) AS score', [$normalised])
            ->whereRaw('similarity(tags.search_name, ?) > ?', [$normalised, self::SIMILARITY])
            ->orderByDesc('score')->limit(self::SUGGESTIONS)->get();

        // Searched before and found something at least once.
        $terms = DB::table('search_terms')->join('search_stats_daily', 'search_stats_daily.term_id', '=', 'search_terms.id')
            ->groupBy('search_terms.id', 'search_terms.normalized_term')
            ->havingRaw('sum(search_stats_daily.searches) > sum(search_stats_daily.zero_result_searches)')
            ->whereRaw('similarity(search_terms.normalized_term, ?) > ?', [$normalised, self::SIMILARITY])
            ->where('search_terms.normalized_term', '<>', $normalised)
            ->selectRaw('search_terms.normalized_term AS word, similarity(search_terms.normalized_term, ?) AS score', [$normalised])
            ->orderByDesc('score')->limit(self::SUGGESTIONS)->get();

        return $tags->concat($terms)->sortByDesc('score')
            ->map(fn ($r) => (string) $r->word)
            ->reject(fn (string $w) => ArabicNormalizer::normalize($w) === $normalised)
            ->unique(fn (string $w) => SearchForm::text($w))
            ->take(self::SUGGESTIONS)->values()->all();
    }

    /** @return Collection<int, Post> */
    public function latest(): Collection
    {
        return Post::query()->live()->whereIn('kind_code', ['news', 'report', 'opinion', 'infographic'])
            ->orderByDesc('published_at')->orderByDesc('posts.id')->limit(self::ITEMS)->get();
    }

    /**
     * Most read in the last seven days (22.5), from the daily counters. Until there are views, the latest
     * items that are not already in the other list.
     *
     * @param  Collection<int, Post>  $latest
     * @return Collection<int, Post>
     */
    public function mostRead(Collection $latest): Collection
    {
        $views = DB::table('post_views_daily')->where('stat_date', '>=', now('Asia/Hebron')->subDays(7)->toDateString())
            ->groupBy('post_id')->selectRaw('post_id, sum(views) AS total');

        $read = Post::query()->live()->joinSub($views, 'v', 'v.post_id', '=', 'posts.id')
            ->orderByDesc('v.total')->orderByDesc('posts.published_at')->limit(self::ITEMS)->get(['posts.*']);

        return $read->isNotEmpty() ? $read : Post::query()->live()->whereNotIn('posts.id', $latest->pluck('id'))
            ->orderByDesc('published_at')->limit(self::ITEMS)->get();
    }
}
