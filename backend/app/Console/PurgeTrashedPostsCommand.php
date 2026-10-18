<?php

namespace App\Console;

use App\Content\PostSaver;
use App\Models\Post;
use Illuminate\Console\Command;

class PurgeTrashedPostsCommand extends Command
{
    protected $signature = 'posts:purge-trashed';

    protected $description = 'Delete for good the posts that have been in the trash for 90 days (their addresses answer 410 from then on)';

    public function handle(PostSaver $saver): int
    {
        $count = 0;

        Post::onlyTrashed()->where('deleted_at', '<', now()->subDays(Post::TRASH_DAYS))->orderBy('id')->each(function (Post $post) use ($saver, &$count) {
            $saver->purge($post);
            $count++;
        });

        $this->info("Deleted {$count} post(s) for good.");

        return self::SUCCESS;
    }
}
