<?php

namespace App\Content;

use App\Models\Post;
use Illuminate\Support\Facades\URL;

/**
 * Secret preview links (decision 21.8). A link is signed by the application and carries its own expiry, so it
 * needs nothing stored: anyone holding it sees the item as it is right now until it runs out, and nobody can
 * make one without the key. Opened on a phone it works like any page. It cannot be revoked one by one; the
 * lifetime is short for that reason.
 */
final class PostPreview
{
    public const HOURS = [24 => '24 ساعة', 48 => '48 ساعة', 72 => '3 أيام', 168 => 'أسبوع'];

    public function url(Post $post, int $hours = 48): string
    {
        if (! array_key_exists($hours, self::HOURS)) {
            throw new \InvalidArgumentException('Unsupported preview lifetime.');
        }

        return URL::temporarySignedRoute('post.preview', now()->addHours($hours), ['post' => $post->id]);
    }
}
