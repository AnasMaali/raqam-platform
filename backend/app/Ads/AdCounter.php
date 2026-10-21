<?php

namespace App\Ads;

use App\Support\BotAgent;
use App\Support\TrackingHash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Counts ad views (13.6) and clicks (13.7) into ad_stats_daily, by Palestine day. A count is kept only when:
 * the agent is not a robot (22.3); the campaign is live in that slot at this moment; the same view (the site's view id
 * for one showing of one ad on one page) was not counted before; the same visitor did not count the same campaign and
 * slot within the last few seconds; and the visitor is under an hourly ceiling. The visitor is an HMAC of the address,
 * so no address is kept anywhere, not even in a cache key. A refused count is silent: the caller never learns why.
 */
final class AdCounter
{
    public function __construct(private AdBoard $board) {}

    /**
     * @param  list<array{campaign_id: int, placement: string}>  $items  ads seen together on one page view
     * @return int how many were counted
     */
    public function impressions(array $items, string $viewId, ?string $visitorIp, ?string $agent): int
    {
        if (BotAgent::isBot($agent)) {
            return 0;
        }
        $visitor = TrackingHash::visitor($visitorIp);
        $counted = 0;
        foreach ($items as $item) {
            $campaign = (int) $item['campaign_id'];
            $placement = (string) $item['placement'];
            if (! $this->board->isLive($campaign, $placement)) {
                continue;
            }
            $one = hash('sha256', "{$visitor}|{$viewId}|{$campaign}|{$placement}");
            $pair = hash('sha256', "{$visitor}|{$campaign}|{$placement}");
            if (! Cache::add("ads:view:{$one}", 1, 86400)
                || ! Cache::add("ads:gap:view:{$pair}", 1, (int) config('raqam.ads.impression_gap'))
                || ! $this->underCeiling("ads:views:{$visitor}", (int) config('raqam.ads.impressions_per_hour'))) {
                continue;
            }
            $this->add($campaign, $placement, 'impressions');
            $counted++;
        }

        return $counted;
    }

    public function click(int $campaign, string $placement, ?string $visitorIp, ?string $agent): bool
    {
        if (BotAgent::isBot($agent) || ! $this->board->isLive($campaign, $placement)) {
            return false;
        }
        $visitor = TrackingHash::visitor($visitorIp);
        $pair = hash('sha256', "{$visitor}|{$campaign}|{$placement}");
        if (! Cache::add("ads:gap:click:{$pair}", 1, (int) config('raqam.ads.click_gap'))
            || ! $this->underCeiling("ads:clicks:{$visitor}", (int) config('raqam.ads.clicks_per_hour'))) {
            return false;
        }
        $this->add($campaign, $placement, 'clicks');

        return true;
    }

    private function underCeiling(string $key, int $perHour): bool
    {
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /** One statement: two counts at the same moment both land (the row is created or added to atomically). The day is Palestine's. */
    private function add(int $campaign, string $placement, string $column): void
    {
        DB::statement(
            "INSERT INTO ad_stats_daily (campaign_id, placement_code, stat_date, {$column})
             VALUES (?, ?, ?, 1)
             ON CONFLICT (campaign_id, placement_code, stat_date) DO UPDATE SET {$column} = ad_stats_daily.{$column} + 1",
            [$campaign, $placement, now('Asia/Hebron')->toDateString()],
        );
    }
}
