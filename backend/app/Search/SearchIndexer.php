<?php

namespace App\Search;

use App\Search\Indexes\AuthorsIndex;
use App\Search\Indexes\GuestsIndex;
use App\Search\Indexes\PostsIndex;
use App\Search\Indexes\SearchIndex;
use App\Search\Indexes\TagsIndex;
use App\Search\Indexes\VideosIndex;
use InvalidArgumentException;

/**
 * Keeps each index equal to what its rule says belongs (10.4, 10.8). A sync re-reads the rows from the database
 * every time, so a late or repeated job can never put back something that left: it only ever writes the truth.
 */
final class SearchIndexer
{
    public function __construct(private readonly MeiliClient $meili) {}

    public static function make(): self
    {
        return new self(MeiliClient::fromConfig());
    }

    /** @return array<string, SearchIndex> */
    public static function indexes(): array
    {
        $all = [new PostsIndex, new VideosIndex, new AuthorsIndex, new GuestsIndex, new TagsIndex];

        return array_combine(array_map(fn (SearchIndex $i) => $i->key(), $all), $all);
    }

    public static function index(string $key): SearchIndex
    {
        return self::indexes()[$key] ?? throw new InvalidArgumentException("Unknown search index {$key}.");
    }

    public static function uid(string $key): string
    {
        return config('raqam.search.prefix').$key;
    }

    public function meili(): MeiliClient
    {
        return $this->meili;
    }

    /** Creates the indexes that are missing and applies every setting, synonyms included. */
    public function setup(): void
    {
        $synonyms = SynonymBook::forEngine();
        foreach (self::indexes() as $key => $index) {
            $this->meili->createIndex(self::uid($key));
            $this->meili->updateSettings(self::uid($key), [...$index->settings(), 'synonyms' => (object) $synonyms]);
        }
    }

    /** Sends the current synonyms to every index (after an edit on the synonyms screen). */
    public function pushSynonyms(bool $wait = false): void
    {
        $synonyms = (object) SynonymBook::forEngine();
        foreach (array_keys(self::indexes()) as $key) {
            $this->meili->updateSettings(self::uid($key), ['synonyms' => $synonyms], $wait);
        }
    }

    /**
     * Writes the documents of the ids that belong and removes the others.
     *
     * @param  list<int>  $ids
     */
    public function sync(string $key, array $ids, bool $wait = false): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }

        $documents = self::index($key)->documents($ids);
        $gone = array_values(array_diff($ids, array_column($documents, 'id')));

        $documents !== [] && $this->meili->addDocuments(self::uid($key), $documents, $wait);
        $gone !== [] && $this->meili->deleteDocuments(self::uid($key), $gone, $wait);
    }

    /**
     * Rebuilds an index from the database without emptying it first, so search keeps answering meanwhile:
     * every row that belongs is written again, and every document the rule no longer covers is removed.
     *
     * @return array{indexed: int, removed: int}
     */
    public function rebuild(string $key): array
    {
        $uid = self::uid($key);
        $index = self::index($key);
        $before = array_flip($this->meili->documentIds($uid));

        $indexed = 0;
        foreach ($index->ids() as $chunk) {
            $documents = $index->documents($chunk);
            $documents !== [] && $this->meili->addDocuments($uid, $documents);
            foreach ($documents as $document) {
                unset($before[$document['id']]);
            }
            $indexed += count($documents);
        }

        $removed = array_keys($before);
        foreach (array_chunk($removed, 1000) as $chunk) {
            $this->meili->deleteDocuments($uid, $chunk);
        }
        $this->meili->settle($uid, 120);

        return ['indexed' => $indexed, 'removed' => count($removed)];
    }
}
