<?php

namespace App\Observers;

use App\Models\Post;
use App\Search\PostIndexer;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after the surrounding transaction commits, so a save that rolls back never reaches the index.
 * A search outage must not block publishing: the error is reported and `search:reindex` repairs the drift.
 */
class PostObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Post $post): void
    {
        $this->guard(fn (PostIndexer $indexer) => $indexer->sync($post));
    }

    public function deleted(Post $post): void
    {
        $this->guard(fn (PostIndexer $indexer) => $indexer->remove($post));
    }

    public function restored(Post $post): void
    {
        $this->saved($post);
    }

    public function forceDeleted(Post $post): void
    {
        $this->deleted($post);
    }

    private function guard(callable $work): void
    {
        if (! $indexer = PostIndexer::fromConfig()) {
            return;
        }

        try {
            $work($indexer);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
