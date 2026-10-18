<?php

namespace App\Content;

/**
 * The public address of a post (decisions 23.2, 23.3): /{prefix}/{id}/{slug}. The id is what identifies the
 * post; the slug is for people and search engines, and any other slug is redirected to this one. Section
 * kinds live under their section's address; the general kinds have a prefix of their own.
 */
final class PostPath
{
    private const PREFIX = [
        'news' => 'news', 'report' => 'report', 'opinion' => 'opinion', 'infographic' => 'infographic',
        'episode' => 'podcast', 'story' => 'success-stories', 'product' => 'made-in-nablus', 'history' => 'history',
        'initiative' => 'initiatives', 'roundtable' => 'round-table', 'awareness' => 'awareness',
    ];

    public static function prefix(string $kind): string
    {
        return self::PREFIX[$kind] ?? throw new \InvalidArgumentException("Unknown kind {$kind}");
    }

    public static function for(string $kind, int $id, string $slug): string
    {
        return '/'.self::prefix($kind).'/'.$id.'/'.$slug;
    }

    /** The address without the slug: what is left to answer "gone" for when a post has been deleted for good. */
    public static function bare(string $kind, int $id): string
    {
        return '/'.self::prefix($kind).'/'.$id;
    }
}
