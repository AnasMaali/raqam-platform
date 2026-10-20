<?php

namespace App\Http\Controllers\Api;

use App\Api\Presenter;
use App\Models\Post;
use App\Models\Video;
use App\Search\Highlighter;
use App\Search\NoResults;
use App\Search\Searcher;
use App\Search\SearchForm;
use App\Search\SearchQuery;
use App\Search\SearchResults;
use App\Search\SearchStats;
use App\Search\SynonymBook;
use App\Video\VideoView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/search (decision 10). Instant results while typing (the site waits 250 ms after the last key),
 * filters, relevance or newest first, the matched words marked in the original text, and, when nothing is
 * found, close words with the latest and most read items. Every row shown is read again through the same
 * visibility rules as the rest of the API. The answer is never cached and never indexed by search engines.
 */
class SearchApiController extends ApiController
{
    /** Meilisearch counts at most 1000 hits; 12 per page. */
    public const MAX_PAGE = 50;

    public function __construct(Presenter $presenter, private Searcher $searcher, private NoResults $none, private VideoView $videos)
    {
        parent::__construct($presenter);
    }

    public function index(Request $request): JsonResponse
    {
        $slug = ['nullable', 'string', 'max:200', 'regex:/^[^\s\/?#%&]+$/u'];
        $q = $this->query($request, [
            'q' => ['required', 'string', 'min:2', 'max:'.SearchStats::MAX_TERM],
            'category' => $slug, 'section' => $slug,
            'kind' => ['nullable', 'in:news,report,opinion,infographic,episode,story,product,history,initiative,roundtable,awareness'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', 'in:relevance,latest'],
            'track' => ['nullable', 'in:1'],
        ] + ['page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE]]);

        $query = new SearchQuery(
            text: trim($q['q']),
            // An unknown category or section matches nothing (0 is never an id, a key never contains "-").
            categoryId: isset($q['category']) ? (int) (DB::table('categories')->where('slug', $q['category'])->where('is_active', true)->value('id') ?? 0) : null,
            sectionKey: isset($q['section']) ? (DB::table('sections')->where('slug', $q['section'])->where('is_visible', true)->value('key') ?? 'none') : null,
            kind: $q['kind'] ?? null,
            from: isset($q['from']) ? CarbonImmutable::parse($q['from'], 'Asia/Hebron')->startOfDay()->utc() : null,
            to: isset($q['to']) ? CarbonImmutable::parse($q['to'], 'Asia/Hebron')->endOfDay()->utc() : null,
            sort: $q['sort'] ?? 'relevance',
            page: (int) ($q['page'] ?? 1),
        );

        $results = $this->searcher->search($query);

        // Counted when the site says the visitor settled on this search (Enter, the results page), not per key.
        if (($q['track'] ?? null) === '1' && $query->page === 1) {
            SearchStats::record($query->text, $results->isEmpty());
        }

        $terms = array_merge(...array_map(fn ($t) => SynonymBook::expand($t), $query->terms ?: ['']));
        $data = [
            'query' => $query->text,
            'engine' => $results->engine,
            'posts' => $this->posts($results, $terms),
            ...$this->groups($results, $terms),
        ];

        if ($results->isEmpty()) {
            $latest = $this->none->latest();
            $data['suggestions'] = $this->none->suggestions($query);
            $data['latest'] = $this->presenter->summaries($latest);
            $data['most_read'] = $this->presenter->summaries($this->none->mostRead($latest));
        }

        $last = max(1, (int) ceil($results->total / SearchQuery::PER_PAGE));
        $keep = array_diff_key($q, ['page' => 1, 'track' => 1]);
        $link = fn (int $page) => '?'.http_build_query($keep + ['page' => $page]);

        return response()->json([
            'data' => $data,
            'meta' => ['page' => $query->page, 'per_page' => SearchQuery::PER_PAGE, 'total' => $results->total, 'last_page' => min($last, self::MAX_PAGE)],
            'links' => [
                'next' => $query->page < min($last, self::MAX_PAGE) ? $link($query->page + 1) : null,
                'prev' => $query->page > 1 ? $link($query->page - 1) : null,
            ],
        ])->withHeaders(self::HEADERS);
    }

    /** Search results are not a page for search engines (10.12), and no cache keeps a visitor's query. */
    public const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store, private'];

    /**
     * The items, in the engine's order, read again from the database and marked. The engine's match positions
     * are used when its copy of the text is the current one; otherwise the words are found again here.
     *
     * @param  list<string>  $terms
     */
    private function posts(SearchResults $results, array $terms): array
    {
        $hits = collect($results->posts)->keyBy('id');
        $posts = Post::query()->live()->whereIn('posts.id', $hits->keys())->get()->sortBy(fn (Post $p) => $hits->keys()->search($p->id))->values();

        return collect($this->presenter->summaries($posts))->map(function (array $summary) use ($hits, $terms) {
            $hit = $hits->get($summary['id']);

            return $summary + ['highlight' => [
                'title' => $this->mark($summary['title'], $hit, 'title_n', $terms),
                'excerpt' => $summary['excerpt'] === null ? null : $this->mark($summary['excerpt'], $hit, 'excerpt_n', $terms),
            ]];
        })->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function groups(SearchResults $results, array $terms): array
    {
        $out = ['videos' => [], 'authors' => [], 'guests' => [], 'tags' => []];

        if ($hits = collect($results->groups['videos'] ?? [])->keyBy('id')->all()) {
            $videos = Video::query()->public()->with(['youtube', 'upload', 'poster'])->whereIn('videos.id', array_keys($hits))->get()->keyBy('id');
            foreach ($hits as $id => $hit) {
                if ($video = $videos->get($id)) {
                    // A light card for the results list; the video's page has the full payload.
                    $out['videos'][] = array_intersect_key($this->videos->payload($video), array_flip(['id', 'source', 'path', 'title', 'duration_seconds', 'thumbnail_url']))
                        + ['highlight' => ['title' => $this->mark($video->title(), $hit, 'title_n', $terms)]];
                }
            }
        }

        foreach (['authors' => 'name', 'guests' => 'name', 'tags' => 'name_ar'] as $group => $column) {
            $hits = collect($results->groups[$group] ?? [])->keyBy('id');
            if ($hits->isEmpty()) {
                continue;
            }
            // The searcher has already checked each of them against its index's rule.
            $rows = DB::table($group)->whereIn('id', $hits->keys())->get()->keyBy('id');
            foreach ($hits as $id => $hit) {
                if ($row = $rows->get($id)) {
                    $name = $row->{$column};
                    $out[$group][] = [
                        'id' => (int) $row->id, 'name' => $name, 'slug' => $row->slug,
                        'path' => '/'.['authors' => 'author', 'guests' => 'guest', 'tags' => 'tag'][$group].'/'.$row->slug,
                        'highlight' => ['name' => $this->mark($name, $hit, 'name_n', $terms)],
                    ];
                }
            }
        }

        return $out;
    }

    /** @param  array{fields: array<string, string>, matches: array<string, list<array{start: int, length: int}>>}|null  $hit */
    private function mark(string $text, ?array $hit, string $field, array $terms): string
    {
        $fresh = $hit !== null && ($hit['fields'][$field] ?? null) === SearchForm::text($text);

        return Highlighter::mark($text, $fresh ? ($hit['matches'][$field] ?? []) : SearchForm::of($text)->matchesOf($terms));
    }
}
