<?php

namespace App\Console;

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;

class SearchSetupCommand extends Command
{
    protected $signature = 'search:setup';

    protected $description = 'Create the Meilisearch indexes and apply their settings (run at every deploy)';

    public function handle(): int
    {
        if (! MeiliClient::configured()) {
            $this->warn('MEILISEARCH_KEY is empty: the engine is off and search runs on PostgreSQL.');

            return self::SUCCESS;
        }

        $meili = MeiliClient::fromConfig();
        // The application holds a key scoped to its own indexes. One that can list the keys is the master key (or
        // as strong); it would let a leak of this server's .env take over the whole engine.
        if ($meili->canListKeys()) {
            $this->error('MEILISEARCH_KEY can manage API keys: it is the master key or as powerful. Make a scoped key with search:create-key.');

            return self::FAILURE;
        }

        try {
            SearchIndexer::make()->setup();
        } catch (SearchUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Indexes ready: '.implode(', ', array_map(SearchIndexer::uid(...), array_keys(SearchIndexer::indexes()))).'.');

        return self::SUCCESS;
    }
}
