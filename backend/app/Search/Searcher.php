<?php

namespace App\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Runs a visitor's search on Meilisearch, and on PostgreSQL when the engine is off or does not answer (10.11).
 * A failure marks the engine down for a short while, so the next visitors go straight to the fallback instead
 * of each waiting for the timeout; the first search after that tries the engine again.
 */
class Searcher
{
    private const DOWN_KEY = 'search:engine-down';

    /** Fields whose match positions are kept, per index. */
    private const FIELDS = [
        'posts' => ['title_n', 'excerpt_n'], 'videos' => ['title_n'], 'authors' => ['name_n'], 'guests' => ['name_n'], 'tags' => ['name_n'],
    ];

    public function __construct(private readonly DatabaseSearch $fallback) {}

    public function search(SearchQuery $query): SearchResults
    {
        if ($query->terms === []) {
            return new SearchResults(MeiliClient::configured() ? SearchResults::ENGINE : SearchResults::FALLBACK, [], 0);
        }

        if (MeiliClient::configured() && ! self::isDown()) {
            try {
                return $this->meili($query);
            } catch (SearchUnavailable $e) {
                Cache::put(self::DOWN_KEY, true, (int) config('raqam.search.down_seconds'));
                Log::warning('Search engine unavailable; searching the database for '.config('raqam.search.down_seconds').'s. '.$e->getMessage());
            }
        }

        return $this->fallback->search($query);
    }

    public static function isDown(): bool
    {
        return Cache::get(self::DOWN_KEY) === true;
    }

    private function meili(SearchQuery $query): SearchResults
    {
        $base = ['q' => $query->form, 'locales' => ['ara'], 'showMatchesPosition' => true];

        $queries = [[
            ...$base,
            'indexUid' => SearchIndexer::uid('posts'),
            'filter' => $this->filter($query),
            'attributesToRetrieve' => ['id', ...self::FIELDS['posts']],
            'hitsPerPage' => SearchQuery::PER_PAGE,
            'page' => $query->page,
            ...($query->sort === 'latest' ? ['sort' => ['published_at:desc']] : []),
        ]];

        $groups = $this->withGroups($query) ? ['videos', 'authors', 'guests', 'tags'] : [];
        foreach ($groups as $key) {
            $queries[] = [...$base, 'indexUid' => SearchIndexer::uid($key), 'attributesToRetrieve' => ['id', ...self::FIELDS[$key]], 'limit' => SearchQuery::GROUP_LIMIT];
        }

        $results = MeiliClient::fromConfig()->multiSearch($queries);
        $hits = function (array $result, string $key) {
            $hits = array_map(fn (array $hit) => [
                'id' => (int) $hit['id'],
                'fields' => array_intersect_key($hit, array_flip(self::FIELDS[$key])),
                'matches' => array_intersect_key($hit['_matchesPosition'] ?? [], array_flip(self::FIELDS[$key])),
            ], $result['hits'] ?? []);

            return $this->stillVisible($key, $hits);
        };

        $out = [];
        foreach ($groups as $i => $key) {
            $out[$key] = $hits($results[$i + 1], $key);
        }

        return new SearchResults(SearchResults::ENGINE, $hits($results[0], 'posts'), (int) ($results[0]['totalHits'] ?? 0), $out);
    }

    /**
     * The index can lag behind the database by a few seconds (a job not run yet, a rebuild in progress). Every
     * hit is checked again against the same rule the index uses, so a draft, a trashed item or the card of a
     * hidden character is never returned, even for that moment.
     *
     * @param  list<array{id: int}>  $hits
     * @return list<array{id: int}>
     */
    private function stillVisible(string $key, array $hits): array
    {
        if ($hits === []) {
            return [];
        }
        $query = SearchIndexer::index($key)->belonging();
        $visible = array_flip($query->whereIn($query->from.'.id', array_column($hits, 'id'))->pluck($query->from.'.id')->map(fn ($id) => (int) $id)->all());

        return array_values(array_filter($hits, fn (array $hit) => isset($visible[$hit['id']])));
    }

    /** Writers, guests, videos and tags come with the first page of an unfiltered search only. */
    public function withGroups(SearchQuery $query): bool
    {
        return $query->page === 1 && ! $query->filtered();
    }

    /**
     * The engine filter, built only from integers and keys checked against a pattern here as well as in the
     * request: no visitor text ever reaches it.
     *
     * @return list<string>
     */
    private function filter(SearchQuery $query): array
    {
        $key = fn (?string $value) => $value === null || preg_match('/^[a-z_]{1,20}$/', $value) === 1 ? $value : throw new InvalidArgumentException('Bad filter key.');

        return array_values(array_filter([
            $query->categoryId !== null ? 'categories = '.(int) $query->categoryId : null,
            $key($query->sectionKey) !== null ? 'section = "'.$query->sectionKey.'"' : null,
            $key($query->kind) !== null ? 'kind = "'.$query->kind.'"' : null,
            $query->from !== null ? 'published_at >= '.$query->from->getTimestamp() : null,
            $query->to !== null ? 'published_at <= '.$query->to->getTimestamp() : null,
        ]));
    }
}
