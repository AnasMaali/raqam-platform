<?php

namespace App\Console;

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;

class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex {index? : posts, videos, authors, guests or tags (all when left out)}';

    protected $description = 'Rebuild the search indexes from the database and remove what no longer belongs (nightly)';

    public function handle(): int
    {
        if (! MeiliClient::configured()) {
            $this->warn('MEILISEARCH_KEY is empty: the engine is off, nothing to rebuild.');

            return self::SUCCESS;
        }

        $keys = $this->argument('index') !== null ? [(string) $this->argument('index')] : array_keys(SearchIndexer::indexes());
        if (array_diff($keys, array_keys(SearchIndexer::indexes())) !== []) {
            $this->error('Unknown index. Use one of: '.implode(', ', array_keys(SearchIndexer::indexes())).'.');

            return self::INVALID;
        }

        try {
            $indexer = SearchIndexer::make();
            $indexer->setup();
            foreach ($keys as $key) {
                $result = $indexer->rebuild($key);
                $this->info("{$key}: {$result['indexed']} indexed, {$result['removed']} removed.");
            }
        } catch (SearchUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
