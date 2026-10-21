<?php

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use Illuminate\Support\Facades\Http;

/*
| Tests that need the real engine. They run against a Meilisearch v1.54.3 with a key scoped to raqam_test_*,
| each test on its own random prefix, and drop their indexes afterwards. Without the engine they FAIL with the
| reason (they are not skipped): CI starts one, and a machine without it should notice.
*/

function meiliTestKey(): string
{
    $file = (getenv('HOME') ?: '').'/.raqam-meili-test-key';
    $key = (string) (getenv('MEILISEARCH_TEST_KEY') ?: (is_readable($file) ? trim((string) file_get_contents($file)) : ''));
    if ($key === '') {
        throw new RuntimeException('These tests need Meilisearch: start it and set MEILISEARCH_TEST_KEY to a key scoped to raqam_test_* (or put it in ~/.raqam-meili-test-key).');
    }

    return $key;
}

/** Points the application at the test engine on a fresh prefix and creates the indexes. */
function useSearchEngine(): void
{
    config([
        'raqam.search.host' => getenv('MEILISEARCH_TEST_HOST') ?: 'http://127.0.0.1:7700',
        'raqam.search.key' => meiliTestKey(),
        'raqam.search.prefix' => 'raqam_test_'.bin2hex(random_bytes(4)).'_',
    ]);
    // Kept aside so the clean-up still reaches the engine after a test pointed the application elsewhere.
    $GLOBALS['raqam_search_test'] = config('raqam.search');
    if (! MeiliClient::fromConfig()->isReachable()) {
        throw new RuntimeException('Meilisearch does not answer at '.config('raqam.search.host').'.');
    }
    SearchIndexer::make()->setup();
}

function dropSearchIndexes(): void
{
    $engine = $GLOBALS['raqam_search_test'] ?? null;
    unset($GLOBALS['raqam_search_test']);
    if ($engine === null || ! str_starts_with((string) $engine['prefix'], 'raqam_test_')) {
        return;
    }
    $meili = new MeiliClient((string) $engine['host'], (string) $engine['key']);
    foreach ($meili->indexes() as $uid) {
        str_starts_with($uid, (string) $engine['prefix']) && $meili->deleteIndex($uid);
    }
}

/** Waits for every queued engine task of this test. */
function settleSearch(): void
{
    foreach (array_keys(SearchIndexer::indexes()) as $key) {
        MeiliClient::fromConfig()->settle(SearchIndexer::uid($key));
    }
}

/** @return list<int> */
function indexedIds(string $key): array
{
    settleSearch();
    $ids = MeiliClient::fromConfig()->documentIds(SearchIndexer::uid($key));
    sort($ids);

    return $ids;
}

/** @return array<string, mixed> the stored document, every field */
function indexedDocument(string $key, int $id): array
{
    settleSearch();

    return (array) Http::baseUrl((string) config('raqam.search.host'))->withToken((string) config('raqam.search.key'))
        ->get('/indexes/'.SearchIndexer::uid($key).'/documents/'.$id)->json();
}
