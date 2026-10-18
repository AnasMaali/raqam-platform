<?php

namespace App\Jobs;

use App\Content\PostPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued with a delay to the publishing time of a scheduled post, so it goes live within seconds (21.9, 10.8). */
class PublishScheduledPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId) {}

    public function handle(PostPublisher $publisher): void
    {
        $publisher->publishIfDue($this->postId);
    }
}
