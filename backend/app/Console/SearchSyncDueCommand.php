<?php

namespace App\Console;

use App\Search\MeiliClient;
use App\Search\SearchIndexer;
use App\Search\SearchSync;
use App\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SearchSyncDueCommand extends Command
{
    protected $signature = 'search:sync-due';

    protected $description = 'Every minute: re-index the items that went live, changed or left in the last minutes (safety net)';

    /** Wider than a minute, so a missed run or a slow queue is covered by the next one. */
    private const WINDOW_MINUTES = 10;

    public function handle(): int
    {
        if (! MeiliClient::configured()) {
            return self::SUCCESS;
        }

        // The database clock decides "the last minutes", the same clock that wrote the times.
        $since = DB::raw("now() - interval '".self::WINDOW_MINUTES." minutes'");
        $posts = DB::table('posts')
            ->where(fn ($q) => $q->whereBetween('published_at', [$since, DB::raw('now()')])
                ->orWhere('updated_at', '>=', $since)
                ->orWhere('deleted_at', '>=', $since))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($posts === []) {
            return self::SUCCESS;
        }

        // Directly, not through the queue: this net must hold even when the queue is stuck.
        try {
            $indexer = SearchIndexer::make();
            $indexer->sync('posts', $posts);
            $indexer->sync('videos', SearchSync::videosOfPosts($posts));
            $indexer->sync('tags', SearchSync::tagsOfPosts($posts));
        } catch (SearchUnavailable $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($posts).' item(s) checked.');

        return self::SUCCESS;
    }
}
