<?php

namespace App\Analytics;

use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The most read posts (22.5) from the daily counters: today, or the last seven days (today included), by Palestine
 * day. Only live posts. Just after midnight today has no counts yet; then "today" is yesterday, and the answer says so.
 * The numbers move once an hour (the transfer), so the answer is kept until the next one.
 */
final class MostRead
{
    public const MAX = 12;

    /** @return array{from: string, to: string, posts: Collection<int, Post>, views: array<int, int>} */
    public function for(string $period, int $limit, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now('Asia/Hebron'))->setTimezone('Asia/Hebron')->startOfDay();
        $limit = max(1, min(self::MAX, $limit));
        $version = (int) Cache::get(StatsFlusher::VERSION, 0);

        $ranking = Cache::remember("stats:most-read:{$version}:{$period}:{$limit}:{$today->toDateString()}", 3600, function () use ($period, $limit, $today) {
            $from = $period === 'week' ? $today->subDays(6) : $today;
            $rows = $this->rank($from, $today, $limit);
            if ($rows === [] && $period === 'day') {
                $from = $today->subDay();
                $rows = $this->rank($from, $from, $limit);

                return ['from' => $from->toDateString(), 'to' => $from->toDateString(), 'rows' => $rows];
            }

            return ['from' => $from->toDateString(), 'to' => $today->toDateString(), 'rows' => $rows];
        });

        // Ranked from the cache, re-checked live: a post unpublished since then leaves the list at once.
        $posts = Post::query()->live()->whereIn('id', array_keys($ranking['rows']))->get()->keyBy('id');
        $ordered = collect(array_keys($ranking['rows']))->filter(fn ($id) => $posts->has($id))->map(fn ($id) => $posts[$id])->values();

        return ['from' => $ranking['from'], 'to' => $ranking['to'], 'posts' => $ordered, 'views' => $ranking['rows']];
    }

    /** @return array<int, int> post id => views, most first */
    private function rank(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        return DB::table('post_views_daily as v')
            ->joinSub(Post::query()->live()->select('posts.id'), 'p', 'p.id', '=', 'v.post_id')
            ->whereBetween('v.stat_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('v.post_id')->orderByRaw('sum(v.views) DESC')->orderByDesc('v.post_id')->limit($limit)
            ->selectRaw('v.post_id, sum(v.views)::bigint AS views')
            ->pluck('views', 'post_id')->map(fn ($n) => (int) $n)->all();
    }
}
