<?php

namespace App\Content;

use App\Events\PostChanged;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Puts scheduled items live when their time comes (decision 21.9). Two things call it: a job delayed to the exact
 * time when the item is saved, and a task that runs every minute as a safety net. Both look at the item as it is
 * now, so an item that was moved, pulled back or deleted in the meantime is left alone.
 */
final class PostPublisher
{
    /** @return bool whether the item went live */
    public function publishIfDue(int $postId): bool
    {
        $published = false;

        DB::transaction(function () use ($postId, &$published) {
            $post = Post::query()->whereKey($postId)->lockForUpdate()->first();   // soft-deleted ones are not found

            if ($post === null || $post->status !== 'scheduled' || $post->published_at === null || $post->published_at->isFuture()) {
                return;
            }

            DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');
            $post->forceFill(['status' => 'published'])->save();
            DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
            DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');
            $published = true;
        });

        if ($published) {
            PostChanged::dispatch($postId, 'content');
        }

        return $published;
    }

    /** @return int how many items went live */
    public function publishAllDue(): int
    {
        $count = 0;

        foreach (Post::query()->where('status', 'scheduled')->where('published_at', '<=', now())->pluck('id') as $id) {
            $count += $this->publishIfDue($id) ? 1 : 0;
        }

        return $count;
    }
}
