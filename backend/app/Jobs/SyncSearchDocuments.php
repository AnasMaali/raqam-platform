<?php

namespace App\Jobs;

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Brings some documents of one index in line with the database. Queued after the save committed, so a save
 * never waits for the engine and never fails because of it (10.8); when the engine is down the job retries,
 * and search:reindex repairs whatever is still off. It decides from the database at run time, not from what
 * was true when it was queued: a delayed job for a scheduled item indexes it only if it is still due and live.
 */
class SyncSearchDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @param  list<int>  $ids */
    public function __construct(public string $index, public array $ids) {}

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
            SearchIndexer::make()->sync($this->index, $this->ids);
        } catch (SearchUnavailable $e) {
            // On a real queue the job is retried. Run in-process (the sync connection) it would turn a save that
            // already committed into an error page, so it gives up quietly and the nightly rebuild repairs the index.
            if ($this->job === null || $this->job->getConnectionName() === 'sync') {
                Log::warning("Search index {$this->index} not updated: ".$e->getMessage());

                return;
            }
            throw $e;
        }
    }
}
