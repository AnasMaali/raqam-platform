<?php

namespace App\Seo;

use App\Content\PostPath;
use App\Markets\RateBoard;
use App\Models\Post;
use App\Services\ServiceBoard;
use App\Weather\WeatherBoard;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the website's sitemaps are made of (decision 23.10); the site writes the XML. Only what a visitor can open and a
 * search engine may index: live posts (an archived item keeps its address but leaves every list, sitemaps included),
 * nothing marked noindex, nothing whose canonical address is another page. Posts come in pages of POSTS_PER_PAGE, the
 * rest of the site in one list, the last 48 hours of news for Google News, and the videos (/sitemaps/videos).
 */
final class SitemapBoard
{
    public const POSTS_PER_PAGE = 2000;

    /** Google News reads articles published in the last two days, at most 1,000 in one sitemap. */
    public const NEWS_HOURS = 48;

    public const NEWS_LIMIT = 1000;

    /** A sitemap holds at most 50,000 addresses; the list of the other pages stays well below it. */
    private const PAGES_LIMIT = 45000;

    public function __construct(private ServiceBoard $services, private WeatherBoard $weather, private RateBoard $rates) {}

    /** @return array{posts_pages: int, posts_total: int, posts_updated_at: ?string, news_total: int} */
    public function index(): array
    {
        $posts = $this->posts();
        $total = (clone $posts)->count();

        return [
            'posts_pages' => max(1, (int) ceil($total / self::POSTS_PER_PAGE)),
            'posts_total' => $total,
            'posts_updated_at' => $this->iso((clone $posts)->max('posts.updated_at')),
            'news_total' => (clone $this->news())->count(),
        ];
    }

    /** @return array{entries: list<array<string, mixed>>, total: int} one page of posts, oldest first so a page keeps its addresses */
    public function postsPage(int $page, int $perPage = self::POSTS_PER_PAGE): array
    {
        $posts = $this->posts();
        $total = (clone $posts)->count();
        $rows = $posts->orderBy('posts.id')->forPage($page, $perPage)->get(['posts.id', 'posts.kind_code', 'posts.slug', 'posts.updated_at']);

        return ['total' => $total, 'entries' => $rows->map(fn ($p) => [
            'path' => PostPath::for($p->kind_code, (int) $p->id, $p->slug), 'updated_at' => $this->iso($p->updated_at),
        ])->all()];
    }

    /** @return list<array<string, mixed>> the news items of the last 48 hours, newest first (23.10, 23.13) */
    public function newsEntries(): array
    {
        $rows = $this->news()->orderByDesc('posts.published_at')->orderByDesc('posts.id')->limit(self::NEWS_LIMIT)
            ->get(['posts.id', 'posts.kind_code', 'posts.slug', 'posts.title', 'posts.published_at']);
        $tags = DB::table('post_tags')->join('tags', 'tags.id', '=', 'post_tags.tag_id')->whereIn('post_tags.post_id', $rows->pluck('id'))
            ->orderBy('tags.name_ar')->get(['post_tags.post_id', 'tags.name_ar'])->groupBy('post_id');

        return $rows->map(fn ($p) => [
            'path' => PostPath::for($p->kind_code, (int) $p->id, $p->slug), 'title' => $p->title, 'published_at' => $this->iso($p->published_at),
            'keywords' => ($tags->get($p->id) ?? collect())->pluck('name_ar')->take(10)->values()->all(),
        ])->all();
    }

    /** @return list<array{path: string, updated_at: ?string}> every page that is not a post or a video */
    public function pageEntries(): array
    {
        $entries = [['path' => '/', 'updated_at' => $this->iso(Post::query()->live()->max('updated_at'))]];
        $add = function (iterable $rows, callable $path, ?string $time = 'updated_at') use (&$entries) {
            foreach ($rows as $row) {
                $entries[] = ['path' => $path($row), 'updated_at' => $time ? $this->iso($row->{$time}) : null];
            }
        };

        $add($this->indexable(DB::table('sections')->where('is_visible', true), 'sections')->orderBy('sort_order')->get(), fn ($r) => SitePaths::section($r->slug));
        $add($this->indexable(DB::table('categories')->where('is_active', true), 'categories')->orderBy('sort_order')->orderBy('id')->get(), fn ($r) => SitePaths::category($r->slug));
        $add($this->indexable(DB::table('pages')->where('is_published', true), 'pages')->orderBy('id')->get(), fn ($r) => SitePaths::page($r->slug));
        $add($this->indexable(DB::table('authors')->whereNull('deleted_at')->where('is_active', true), 'authors')->orderBy('id')->get(), fn ($r) => SitePaths::author($r->slug));
        $add($this->indexable(DB::table('guests')->whereNull('deleted_at'), 'guests')->orderBy('id')->get(), fn ($r) => SitePaths::guest($r->slug));
        $add($this->indexable(DB::table('characters')->where('is_active', true), 'characters')->orderBy('sort_order')->orderBy('id')->get(), fn ($r) => SitePaths::character($r->slug));
        $add($this->indexable(DB::table('playlists')->where('is_visible', true), 'playlists')->orderBy('sort_order')->orderBy('id')->get(), fn ($r) => SitePaths::playlist($r->slug));
        // A tag page exists while at least one live item carries it (the same rule as /tags).
        $live = DB::table('post_tags')->join('posts', 'posts.id', '=', 'post_tags.post_id')->where('posts.status', 'published')->where('posts.published_at', '<=', now())
            ->whereNull('posts.deleted_at')->whereNotIn('posts.id', Post::hiddenCharacterCards())->groupBy('post_tags.tag_id')->selectRaw('post_tags.tag_id, max(posts.updated_at) as updated_at');
        $add($this->indexable(DB::table('tags')->joinSub($live, 'l', 'l.tag_id', '=', 'tags.id'), 'tags')->orderBy('tags.id')->get(['tags.slug', 'l.updated_at']), fn ($r) => SitePaths::tag($r->slug));

        foreach ($this->services->groups() as $group) {
            foreach ($group['services'] as $service) {
                $entries[] = ['path' => $service['path'], 'updated_at' => null];
            }
        }
        $entries[] = ['path' => '/currencies', 'updated_at' => null];
        foreach ($this->rates->rows() as $currency) {
            $entries[] = ['path' => $currency['path'], 'updated_at' => null];
        }
        $entries[] = ['path' => '/weather', 'updated_at' => null];
        foreach ($this->weather->cities() as $city) {
            $entries[] = ['path' => $city['path'], 'updated_at' => null];
        }
        $entries[] = ['path' => '/video', 'updated_at' => null];

        return array_slice(array_values(array_unique($entries, SORT_REGULAR)), 0, self::PAGES_LIMIT);
    }

    /** Live posts a search engine may index. */
    private function posts(): Builder
    {
        return $this->indexable(Post::query()->live()->toBase(), 'posts');
    }

    /** News kinds (the general ones; section items are articles, not news) published in the last 48 hours. */
    private function news(): Builder
    {
        return $this->posts()->whereIn('posts.kind_code', DB::table('post_kinds')->whereNull('section_id')->select('code'))
            ->where('posts.published_at', '>', now()->subHours(self::NEWS_HOURS));
    }

    /** Leaves out rows whose SEO says "do not index", or names a canonical address of its own (the real page is elsewhere). */
    private function indexable(Builder $query, string $table): Builder
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('seo_meta')->whereColumn('seo_meta.id', $table.'.seo_meta_id')
            ->where(fn ($w) => $w->where('seo_meta.robots_noindex', true)->orWhereNotNull('seo_meta.canonical_url')));
    }

    private function iso(mixed $time): ?string
    {
        return $time === null ? null : Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
