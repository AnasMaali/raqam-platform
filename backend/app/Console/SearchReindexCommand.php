<?php

namespace App\Console;

use App\Search\PostIndexer;
use Illuminate\Console\Command;

class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild the Meilisearch posts index from the database';

    public function handle(): int
    {
        if (! $indexer = PostIndexer::fromConfig()) {
            $this->error('MEILISEARCH_KEY is not set.');

            return self::FAILURE;
        }

        $this->info("Indexed {$indexer->rebuild()} posts.");

        return self::SUCCESS;
    }
}
