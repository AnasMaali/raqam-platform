<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something about a post changed after a save was committed: published, edited, unpublished, deleted.
 * The site's cache refresh (21.16), the search index and the sitemaps listen for it.
 */
final class PostChanged
{
    use Dispatchable;

    public function __construct(public readonly int $postId, public readonly string $change) {}
}
