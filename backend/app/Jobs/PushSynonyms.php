<?php

namespace App\Jobs;

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sends the synonyms as they are now to every index (10.9). It reads the tables when it runs, so the last
 * of several quick edits wins and nothing is lost if one push fails; search:setup sends them again at deploy.
 */
class PushSynonyms implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600, 1800];
    }

    public function handle(): void
    {
        if (! MeiliClient::configured()) {
            return;
        }

        try {
            SearchIndexer::make()->pushSynonyms();
        } catch (SearchUnavailable $e) {
            if ($this->job === null || $this->job->getConnectionName() === 'sync') {
                Log::warning('Synonyms not sent to the search engine: '.$e->getMessage());

                return;
            }
            throw $e;
        }
    }
}
