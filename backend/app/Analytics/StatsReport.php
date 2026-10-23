<?php

namespace App\Analytics;

use App\Ads\AdReport;
use App\Search\SearchStatsReport;
use Illuminate\Support\Facades\DB;

/**
 * Everything the statistics screen shows for a period (22.4–22.11), in one place with the other modules' numbers
 * (22.9): visits, sources, devices, interactions, the most read and readings by category, section and author; search,
 * ads, newsletter, chatbot, contact buttons and messages. Days are Palestine days. Today comes from the stored rows and
 * Redis together (the larger of the two), so it is live rather than an hour behind.
 *
 * "Visitors" over several days is the sum of each day's visitors: one person on three days is three, because the
 * daily fingerprint (22.2) cannot recognise anybody from one day to the next.
 */
final class StatsReport
{
    public const TOP = 10;

    /** @var array<string, mixed> */
    public array $data;

    public function __construct(public readonly StatsPeriod $period)
    {
        $this->data = $this->build();
    }

    public static function for(string $period, mixed $from = null, mixed $to = null): self
    {
        return new self(StatsPeriod::of($period, $from, $to));
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        [$from, $to] = $this->period->range();
        $today = StatsKeys::today();
        $live = $this->period->includes($today) ? LiveToday::counters() : null;

        // Days, zero-filled; today the larger of the stored row and Redis.
        $stored = DB::table('site_stats_daily')->whereBetween('stat_date', [$from, $to])->get()->keyBy(fn ($r) => (string) $r->stat_date);
        $days = [];
        foreach ($this->period->dates() as $date) {
            $row = $stored->get($date);
            $pv = (int) ($row->page_views ?? 0);
            $uv = (int) ($row->unique_visitors ?? 0);
            if ($live !== null && $date === $today) {
                $pv = max($pv, $live['page_views']);
                $uv = min(max($uv, $live['visitors']), $pv);
            }
            $days[] = ['date' => $date, 'page_views' => $pv, 'visitors' => $uv];
        }

        $sources = $this->summed('traffic_sources_daily', 'source_code', 'visits', $live['sources'] ?? []);
        $devices = $this->summed('device_stats_daily', 'device_type', 'visits', $live['devices'] ?? []);
        $events = $this->summed('event_stats_daily', 'event_code', 'count', $live['events'] ?? []);
        $posts = $this->summed('post_views_daily', 'post_id', 'views', $live['posts'] ?? []);
        $videos = $this->summed('video_plays_daily', 'video_id', 'plays', $live['videos'] ?? []);

        $pageViews = array_sum(array_column($days, 'page_views'));
        $visits = array_sum($sources);
        $previous = $this->previousTotals();

        return [
            'from' => $from, 'to' => $to, 'problem' => $this->period->problem, 'live' => $live !== null,
            'totals' => [
                'page_views' => $pageViews,
                'visitors' => array_sum(array_column($days, 'visitors')),
                'visits' => $visits,
                'pages_per_visit' => $visits === 0 ? null : number_format($pageViews / $visits, 1),
                'readings' => array_sum($posts),
                'previous_page_views' => $previous['page_views'],
                'previous_visitors' => $previous['visitors'],
            ],
            'days' => $days,
            'sources' => $this->named($sources, DB::table('traffic_sources')->pluck('name_ar', 'code')->all()),
            'devices' => $this->named($devices, ['mobile' => 'جوال', 'tablet' => 'تابلت', 'desktop' => 'كمبيوتر']),
            'events' => $this->named($events, DB::table('event_types')->pluck('name_ar', 'code')->all()),
            'contact_buttons' => $this->contactButtons(),
            'videos' => $this->topVideos($videos),
            'posts' => $this->topPosts($posts),
            'by_category' => $this->byCategory($posts),
            'by_section' => $this->bySection($posts),
            'by_author' => $this->byAuthor($posts),
            'not_found' => DB::table('not_found_paths')->whereNull('resolved_at')->orderByDesc('hits')->limit(5)->get(['path', 'hits'])
                ->map(fn ($r) => ['path' => $r->path, 'hits' => (int) $r->hits])->all(),
            'modules' => $this->modules(),
        ];
    }

    /**
     * The sum per key over the period: stored days before today, plus today as the larger of stored and live.
     *
     * @param  array<string|int, int>  $live
     * @return array<string|int, int> key => total, largest first
     */
    private function summed(string $table, string $key, string $column, array $live): array
    {
        [$from, $to] = $this->period->range();
        $today = StatsKeys::today();
        $rows = DB::table($table)->whereBetween('stat_date', [$from, $to])->where('stat_date', '<>', $today)
            ->groupBy($key)->selectRaw("{$key} AS k, sum({$column})::bigint AS n")->pluck('n', 'k')->map(fn ($n) => (int) $n)->all();
        if ($this->period->includes($today)) {
            $todayRows = DB::table($table)->where('stat_date', $today)->pluck($column, $key)->map(fn ($n) => (int) $n)->all();
            foreach ($todayRows + $live as $k => $_) {
                $rows[$k] = ($rows[$k] ?? 0) + max($todayRows[$k] ?? 0, $live[$k] ?? 0);
            }
        }
        arsort($rows);

        return $rows;
    }

    /**
     * @param  array<string|int, int>  $counts
     * @param  array<string, string>  $names
     * @return list<array{code: string, name: string, count: int, share: ?string}>
     */
    private function named(array $counts, array $names): array
    {
        $total = array_sum($counts);
        $rows = [];
        foreach ($counts as $code => $n) {
            if (! isset($names[$code]) || $n <= 0) {
                continue;
            }
            $rows[] = ['code' => (string) $code, 'name' => $names[$code], 'count' => $n, 'share' => $total === 0 ? null : number_format($n * 100 / $total, 1)];
        }

        return $rows;
    }

    /** @return array{page_views: int, visitors: int} the same number of days just before the period (stored rows only) */
    private function previousTotals(): array
    {
        $length = count($this->period->dates());
        $end = $this->period->from->subDay();
        $row = DB::table('site_stats_daily')->whereBetween('stat_date', [$end->subDays($length - 1)->toDateString(), $end->toDateString()])
            ->selectRaw('coalesce(sum(page_views), 0)::bigint AS pv, coalesce(sum(unique_visitors), 0)::bigint AS uv')->first();

        return ['page_views' => (int) $row->pv, 'visitors' => (int) $row->uv];
    }

    /** @return list<array{channel: string, name: string, count: int}> the services' contact buttons (14.9), by channel */
    private function contactButtons(): array
    {
        $names = ['whatsapp' => 'واتساب', 'phone' => 'اتصال', 'email' => 'بريد'];
        $counts = DB::table('service_contact_clicks_daily')->whereBetween('stat_date', $this->period->range())
            ->groupBy('channel')->selectRaw('channel, sum(clicks)::bigint AS n')->pluck('n', 'channel')->all();

        return collect($names)->map(fn ($name, $channel) => ['channel' => $channel, 'name' => $name, 'count' => (int) ($counts[$channel] ?? 0)])->values()->all();
    }

    /** @param  array<int, int>  $videos */
    private function topVideos(array $videos): array
    {
        $top = array_slice($videos, 0, 5, true);
        $titles = DB::table('videos')->leftJoin('youtube_videos', 'youtube_videos.video_id', '=', 'videos.id')->whereIn('videos.id', array_keys($top))
            ->selectRaw('videos.id, coalesce(videos.display_title, youtube_videos.source_title) AS title')->pluck('title', 'id')->all();

        return collect($top)->map(fn ($n, $id) => ['id' => (int) $id, 'title' => (string) ($titles[$id] ?? '—'), 'count' => $n])->values()->all();
    }

    /** @param  array<int, int>  $posts */
    private function topPosts(array $posts): array
    {
        $top = array_slice($posts, 0, self::TOP, true);
        $rows = DB::table('posts')->leftJoin('categories', 'categories.id', '=', 'posts.category_id')->join('post_kinds', 'post_kinds.code', '=', 'posts.kind_code')
            ->whereIn('posts.id', array_keys($top))->get(['posts.id', 'posts.title', 'posts.status', 'posts.deleted_at', 'categories.name_ar AS category', 'post_kinds.name_ar AS kind'])->keyBy('id');

        return collect($top)->filter(fn ($n, $id) => $rows->has($id))->map(fn ($n, $id) => [
            'id' => (int) $id, 'title' => $rows[$id]->title, 'kind' => $rows[$id]->kind, 'category' => $rows[$id]->category,
            'live' => $rows[$id]->status === 'published' && $rows[$id]->deleted_at === null, 'count' => $n,
        ])->values()->all();
    }

    /** @param  array<int, int>  $posts */
    private function byCategory(array $posts): array
    {
        $rows = DB::table('posts')->join('categories', 'categories.id', '=', 'posts.category_id')
            ->leftJoin('categories as parents', 'parents.id', '=', 'categories.parent_id')
            ->whereIn('posts.id', array_keys($posts))->get(['posts.id', 'categories.name_ar', 'parents.name_ar AS parent']);

        return $this->grouped($rows->mapWithKeys(fn ($r) => [$r->id => [$r->parent === null ? $r->name_ar : $r->parent.' ← '.$r->name_ar]])->all(), $posts);
    }

    /** @param  array<int, int>  $posts */
    private function bySection(array $posts): array
    {
        $rows = DB::table('posts')->join('post_kinds', 'post_kinds.code', '=', 'posts.kind_code')->join('sections', 'sections.id', '=', 'post_kinds.section_id')
            ->whereIn('posts.id', array_keys($posts))->get(['posts.id', 'sections.name_ar']);

        return $this->grouped($rows->mapWithKeys(fn ($r) => [$r->id => [$r->name_ar]])->all(), $posts);
    }

    /** A post with two authors counts fully for each; one without an author counts for the team. */
    private function byAuthor(array $posts): array
    {
        $authors = DB::table('post_authors')->join('authors', 'authors.id', '=', 'post_authors.author_id')
            ->whereIn('post_authors.post_id', array_keys($posts))->get(['post_authors.post_id', 'authors.name'])
            ->groupBy('post_id')->map(fn ($g) => $g->pluck('name')->all())->all();
        $names = [];
        foreach (array_keys($posts) as $id) {
            $names[$id] = $authors[$id] ?? ['فريق رَقَم'];
        }

        return $this->grouped($names, $posts);
    }

    /**
     * @param  array<int, list<string>>  $groups  post id => the names it counts for
     * @param  array<int, int>  $posts
     * @return list<array{name: string, count: int, posts: int}>
     */
    private function grouped(array $groups, array $posts): array
    {
        $totals = [];
        foreach ($groups as $id => $names) {
            foreach ($names as $name) {
                $totals[$name] ??= ['name' => $name, 'count' => 0, 'posts' => 0];
                $totals[$name]['count'] += $posts[$id] ?? 0;
                $totals[$name]['posts']++;
            }
        }
        usort($totals, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_slice($totals, 0, self::TOP);
    }

    /** @return array<string, array<string, mixed>> the other modules over the same days (22.9) */
    private function modules(): array
    {
        [$from, $to] = $this->period->range();
        $start = $this->period->from->utc();
        $end = $this->period->to->addDay()->utc();

        $search = SearchStatsReport::for('custom', $from, $to);

        $ads = DB::table('ad_stats_daily')->whereBetween('stat_date', [$from, $to])
            ->selectRaw('coalesce(sum(impressions), 0)::bigint AS views, coalesce(sum(clicks), 0)::bigint AS clicks, count(DISTINCT campaign_id) AS campaigns')->first();

        $questions = DB::table('bot_messages')->where('role', 'user')->whereNotNull('match_score')->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $asked = (clone $questions)->count();
        $answered = (clone $questions)->whereNotNull('matched_entry_id')->count();

        return [
            'search' => ['searches' => $search->searches, 'zero' => $search->zero, 'top' => array_slice(array_map(fn ($r) => (string) $r->term, $search->top), 0, 5)],
            'ads' => ['views' => (int) $ads->views, 'clicks' => (int) $ads->clicks, 'campaigns' => (int) $ads->campaigns,
                'ctr' => AdReport::ctr((int) $ads->views, (int) $ads->clicks)],
            'newsletter' => [
                'joined' => DB::table('subscribers')->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end)->count(),
                'left' => DB::table('subscribers')->where('unsubscribed_at', '>=', $start)->where('unsubscribed_at', '<', $end)->count(),
                'active' => DB::table('subscribers')->where('status', 'active')->count(),
            ],
            'chatbot' => [
                'conversations' => DB::table('bot_conversations')->where('started_at', '>=', $start)->where('started_at', '<', $end)->count(),
                'questions' => $asked, 'answered' => $answered, 'rate' => $asked === 0 ? null : number_format($answered * 100 / $asked, 1),
            ],
            'contact' => ['messages' => DB::table('contact_messages')->where('created_at', '>=', $start)->where('created_at', '<', $end)->count()],
        ];
    }

    /** The change from the previous period as a signed percentage, or null when there is nothing to compare with. */
    public static function change(int $now, int $before): ?string
    {
        return $before === 0 ? null : ($now >= $before ? '+' : '−').number_format(abs($now - $before) * 100 / $before, 1).'%';
    }
}
