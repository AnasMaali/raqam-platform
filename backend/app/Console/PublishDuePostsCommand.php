<?php

namespace App\Console;

use App\Content\PostPublisher;
use Illuminate\Console\Command;

class PublishDuePostsCommand extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Put scheduled posts live when their time has come (the safety net behind the delayed jobs)';

    public function handle(PostPublisher $publisher): int
    {
        $count = $publisher->publishAllDue();
        $this->info("Published {$count} post(s).");

        return self::SUCCESS;
    }
}
