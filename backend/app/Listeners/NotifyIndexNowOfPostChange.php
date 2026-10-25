<?php

namespace App\Listeners;

use App\Events\PostChanged;
use App\Seo\IndexNow;

class NotifyIndexNowOfPostChange
{
    public function handle(PostChanged $event): void
    {
        if ($event->change !== 'draft' && IndexNow::configured()) {
            IndexNow::post($event->postId);   // a draft was never on the site, so no engine knows its address
        }
    }
}
