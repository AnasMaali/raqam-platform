<?php

namespace App\Http\Controllers;

use App\Content\BodyRenderer;
use App\Models\Post;
use Illuminate\Http\Response;

/**
 * The page behind a preview link. Not for search engines or caches, and it says so in every way it can; the
 * real site shows the item through the API once it is published.
 */
class PostPreviewController extends Controller
{
    public function show(Post $post, BodyRenderer $renderer): Response
    {
        $post->load(['cover', 'category', 'authors', 'tags']);

        $response = response()->view('preview.post', [
            'post' => $post,
            'body' => $renderer->html($post->body),
            'expires' => request()->query('expires'),
        ]);

        return $response->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }
}
