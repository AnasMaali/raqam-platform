<?php

namespace App\Http\Controllers\Api;

use App\Navigation\MenuBoard;
use App\Seo\FeedBoard;
use App\Seo\LlmsBoard;
use App\Seo\RobotsPolicy;
use App\Seo\SitemapBoard;
use App\Settings\SiteProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every page of the website is built around: the platform's own settings (21.13), the menus (21.12), and the data of
 * the sitemaps (23.10) and RSS feeds (23.12). The website turns these into its header, footer, XML and feeds.
 */
class SiteApiController extends ApiController
{
    public function site(SiteProfile $profile): JsonResponse
    {
        return response()->json(['data' => $profile->payload()]);
    }

    public function menus(MenuBoard $menus): JsonResponse
    {
        return response()->json(['data' => $menus->all()]);
    }

    /** Who may crawl the site (GEO): the website writes robots.txt from it. */
    public function robots(RobotsPolicy $policy): JsonResponse
    {
        return response()->json(['data' => $policy->payload()]);
    }

    /** What llms.txt says about the platform (GEO): the website writes the Markdown from it. */
    public function llms(LlmsBoard $llms): JsonResponse
    {
        return response()->json(['data' => $llms->payload()]);
    }

    public function sitemaps(SitemapBoard $sitemaps): JsonResponse
    {
        $index = $sitemaps->index();
        $list = [['type' => 'pages', 'path' => '/sitemaps/pages', 'updated_at' => null]];
        for ($page = 1; $page <= $index['posts_pages']; $page++) {
            $list[] = ['type' => 'posts', 'path' => '/sitemaps/posts?page='.$page, 'updated_at' => $page === $index['posts_pages'] ? $index['posts_updated_at'] : null];
        }
        $list[] = ['type' => 'news', 'path' => '/sitemaps/news', 'updated_at' => null];
        $list[] = ['type' => 'videos', 'path' => '/sitemaps/videos', 'updated_at' => null];

        return response()->json(['data' => $list, 'meta' => ['posts_total' => $index['posts_total'], 'posts_per_page' => SitemapBoard::POSTS_PER_PAGE, 'news_total' => $index['news_total']]]);
    }

    public function sitemapPosts(Request $request, SitemapBoard $sitemaps): JsonResponse
    {
        $page = $this->page($request);
        $data = $sitemaps->postsPage($page);
        $last = max(1, (int) ceil($data['total'] / SitemapBoard::POSTS_PER_PAGE));
        if ($page > $last) {
            return $this->notFound();
        }

        return response()->json(['data' => $data['entries'], 'meta' => ['page' => $page, 'per_page' => SitemapBoard::POSTS_PER_PAGE, 'total' => $data['total'], 'last_page' => $last]]);
    }

    public function sitemapNews(SitemapBoard $sitemaps, SiteProfile $profile): JsonResponse
    {
        return response()->json(['data' => $sitemaps->newsEntries(), 'meta' => ['publication' => ['name' => $profile->payload()['name'], 'language' => 'ar'], 'hours' => SitemapBoard::NEWS_HOURS]]);
    }

    public function sitemapPages(SitemapBoard $sitemaps): JsonResponse
    {
        return response()->json(['data' => $sitemaps->pageEntries()]);
    }

    public function feed(FeedBoard $feeds): JsonResponse
    {
        return response()->json(['data' => $feeds->latest()]);
    }

    public function categoryFeed(string $slug, FeedBoard $feeds): JsonResponse
    {
        return ($feed = $feeds->category($slug)) === null ? $this->notFound() : response()->json(['data' => $feed]);
    }

    public function sectionFeed(string $slug, FeedBoard $feeds): JsonResponse
    {
        return ($feed = $feeds->section($slug)) === null ? $this->notFound() : response()->json(['data' => $feed]);
    }
}
