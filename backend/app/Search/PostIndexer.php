<?php

namespace App\Search;

use App\Models\Post;

/**
 * Keeps the posts index equal to "published, not deleted, already live".
 * A draft, scheduled, archived, trashed or future-dated post is never sent, and is removed if it was.
 */
final class PostIndexer
{
    public function __construct(private readonly MeiliClient $meili, private readonly string $index) {}

    public static function fromConfig(): ?self
    {
        return config('raqam.search.key')
            ? new self(MeiliClient::fromConfig(), (string) config('raqam.search.posts_index'))
            : null;
    }

    public static function belongsInIndex(Post $post): bool
    {
        return $post->status === 'published'
            && $post->deleted_at === null
            && $post->published_at !== null
            && $post->published_at->lte(now());
    }

    public function sync(Post $post, bool $wait = false): void
    {
        if (self::belongsInIndex($post)) {
            $post->loadMissing('category');
            $this->meili->addDocuments($this->index, [PostDocument::from($post)], $wait);
        } else {
            $this->meili->deleteDocument($this->index, $post->id, $wait);
        }
    }

    public function remove(Post $post, bool $wait = false): void
    {
        $this->meili->deleteDocument($this->index, $post->id, $wait);
    }

    /** Rebuilds the index from the database; also drops documents of posts that should not be there. */
    public function rebuild(): int
    {
        $this->prepare();
        $this->meili->deleteAllDocuments($this->index);

        $count = 0;
        Post::query()->with('category')->where('status', 'published')
            ->where('published_at', '<=', now())
            ->chunkById(500, function ($posts) use (&$count) {
                $this->meili->addDocuments($this->index, $posts->map(fn (Post $post) => PostDocument::from($post))->all());
                $count += $posts->count();
            });

        return $count;
    }

    /** Creates the index when missing and applies the settings. */
    public function prepare(): void
    {
        try {
            $this->meili->createIndex($this->index);
        } catch (\Throwable) {
            // "index_already_exists" fails the task; the settings below are idempotent either way.
        }
        $this->meili->updateSettings($this->index, PostDocument::settings());
    }
}
