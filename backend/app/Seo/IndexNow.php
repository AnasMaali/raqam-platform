<?php

namespace App\Seo;

use App\Content\PostPath;
use App\Jobs\SubmitIndexNow;
use Illuminate\Support\Facades\DB;

/**
 * Tells the search engines that take IndexNow (Bing, Yandex, Seznam, Naver …) which addresses changed, right after a
 * save is committed (decision 23.11): a published, edited, unpublished or deleted post, and the same for a static page.
 * A deleted address is sent too, so the engine comes back and finds the 404/410. Nothing is sent without a key or the
 * site's address, and nothing marked noindex.
 */
final class IndexNow
{
    public const KEY_PATTERN = '/^[A-Za-z0-9-]{8,128}$/';

    public static function configured(): bool
    {
        $site = (string) config('raqam.site_url');

        return preg_match(self::KEY_PATTERN, (string) config('raqam.indexnow.key')) === 1
            && (str_starts_with($site, 'https://') || (! app()->isProduction() && str_starts_with($site, 'http://')));
    }

    /** @param  list<string>  $paths  addresses on the site, each starting with / */
    public static function submit(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && str_starts_with($p, '/') && ! str_starts_with($p, '//'))));
        if ($paths !== [] && self::configured()) {
            SubmitIndexNow::dispatch($paths)->afterCommit();
        }
    }

    /** A post changed: its address, and the address it had a moment ago if its slug just changed (that one now answers 301). */
    public static function post(int $postId): void
    {
        $post = DB::table('posts')->where('id', $postId)->first(['id', 'kind_code', 'slug', 'seo_meta_id']);
        if ($post === null || self::noindex($post->seo_meta_id)) {
            return;
        }
        $path = PostPath::for($post->kind_code, (int) $post->id, $post->slug);
        $moved = DB::table('redirects')->where('to_path', $path)->where('created_at', '>', DB::raw("now() - interval '1 minute'"))->pluck('from_path')->all();

        self::submit([$path, ...$moved]);
    }

    public static function noindex(?int $seoMetaId): bool
    {
        return $seoMetaId !== null && (bool) DB::table('seo_meta')->where('id', $seoMetaId)->value('robots_noindex');
    }
}
