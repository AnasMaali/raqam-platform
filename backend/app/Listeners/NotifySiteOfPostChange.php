<?php

namespace App\Listeners;

use App\Content\PostPath;
use App\Events\PostChanged;
use App\Jobs\SendSiteWebhook;
use Illuminate\Support\Facades\DB;

class NotifySiteOfPostChange
{
    public function handle(PostChanged $event): void
    {
        if (! SendSiteWebhook::configured() || $event->change === 'draft') {
            return;   // a draft is not on the site, so there is nothing to refresh
        }

        $post = DB::table('posts')->where('id', $event->postId)->first(['id', 'kind_code', 'slug']);

        SendSiteWebhook::notify([
            'type' => 'post', 'id' => $event->postId, 'change' => $event->change,
            'paths' => $post ? [PostPath::for($post->kind_code, $post->id, $post->slug)] : [],
        ]);
    }
}
