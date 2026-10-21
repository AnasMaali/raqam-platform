<?php

namespace App\Ads;

use App\Api\Presenter;
use App\Jobs\SendSiteWebhook;
use App\Models\AdPlacement;
use App\Models\HouseAd;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The ad slots as the site gets them (decision 13): every slot with its reserved sizes (13.10), the campaigns that may
 * fill it now with their weight for rotation (13.5), and what an empty slot shows (13.13). Read from the live_ad_campaigns
 * view, the one place that decides what is live (13.4). Cached, dropped on every change, and never kept past the next
 * moment a campaign starts or ends, so a campaign appears and disappears on time without anyone touching it.
 */
final class AdBoard
{
    private const TTL = 300;

    public const LABEL = 'إعلان';

    public const REL = 'sponsored noopener';

    public function __construct(private Presenter $presenter) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function slots(): array
    {
        return $this->remember('slots', fn () => $this->build());
    }

    /**
     * The campaigns that may be counted now, per slot: "campaign:slot" => [starts, ends] (Unix seconds). The times are
     * checked again at each count, so a cached copy never counts a campaign a second after it ended.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function liveKeys(): array
    {
        return $this->remember('live', function () {
            $on = AdPlacements::onCodes();
            $keys = [];
            foreach (DB::table('live_ad_campaigns')->whereIn('placement_code', $on ?: [''])->get(['id', 'placement_code', 'starts_at', 'ends_at']) as $row) {
                $keys[$row->id.':'.$row->placement_code] = [Carbon::parse($row->starts_at)->getTimestamp(), Carbon::parse($row->ends_at)->getTimestamp()];
            }

            return $keys;
        });
    }

    public function isLive(int $campaignId, string $placement): bool
    {
        $span = $this->liveKeys()[$campaignId.':'.$placement] ?? null;
        $now = now()->getTimestamp();

        return $span !== null && $span[0] <= $now && $now < $span[1];
    }

    /**
     * Something about ads changed (called once per save by the services that write ads): drop the cached answers after
     * the commit (or a reader could cache the old ones again first; a rolled-back save drops nothing) and tell the site.
     */
    public static function changed(string $change): void
    {
        DB::afterCommit(function () use ($change) {
            Cache::increment('ads:version');
            SendSiteWebhook::notify(['type' => 'ads', 'change' => $change, 'paths' => []]);
        });
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    private function build(): array
    {
        $flags = AdPlacements::flags();
        $live = DB::table('live_ad_campaigns as c')
            ->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->orderByDesc('c.priority')->orderBy('c.id')
            ->get(['c.id', 'c.placement_code', 'c.priority', 'c.starts_at', 'c.ends_at', 'o.name as advertiser'])
            ->groupBy('placement_code');
        $creatives = DB::table('ad_creatives')->whereIn('campaign_id', $live->flatten()->pluck('id')->unique()->all() ?: [0])->get()
            ->groupBy('campaign_id')->map(fn ($rows) => $rows->pluck('media_id', 'device')->map(fn ($id) => (int) $id));
        $this->presenter->preload($creatives->flatten()->all());

        $slots = [];
        foreach (AdPlacements::all() as $placement) {
            $on = AdPlacements::isOn($placement, $flags);
            $ads = [];
            foreach ($on ? $live->get($placement->code, collect()) : [] as $row) {
                $images = $this->images($placement, $creatives->get($row->id, collect())->all());
                if ($images === null) {
                    continue;   // a picture the slot needs went private or away: the campaign cannot show here
                }
                $ads[] = [
                    'id' => (int) $row->id, 'weight' => (int) $row->priority, 'advertiser' => $row->advertiser,
                    'starts_at' => $this->presenter->iso($row->starts_at), 'ends_at' => $this->presenter->iso($row->ends_at),
                    'images' => $images, 'click_url' => AdClickLink::url((int) $row->id, $placement->code), 'rel' => self::REL,
                ];
            }

            $slots[] = [
                'code' => $placement->code, 'name' => $placement->name_ar, 'active' => $on,
                'sizes' => ['desktop' => $placement->size('desktop'), 'mobile' => $placement->size('mobile')],
                'lazy' => $placement->lazy_load,
                'frequency_cap_hours' => $placement->frequency_cap_hours,
                'empty' => $placement->empty_behavior,
                'ads' => $ads,
                'house' => $on && $placement->empty_behavior === 'house' ? $this->house($placement) : null,
            ];
        }

        $client = (string) config('raqam.ads.adsense_client');

        return ['data' => $slots, 'meta' => [
            'label' => self::LABEL,
            'rotation' => 'weighted',
            'generated_at' => $this->presenter->iso(now()),
            'adsense' => ['enabled' => $client !== '', 'client' => $client !== '' ? $client : null],
        ]];
    }

    /**
     * The pictures of a campaign for a slot: one per device the slot has a size for. Null when one it needs is missing.
     *
     * @param  array<string, int>  $ids
     * @return array{desktop: array<string, mixed>|null, mobile: array<string, mixed>|null}|null
     */
    private function images(AdPlacement $placement, array $ids): ?array
    {
        $images = ['desktop' => null, 'mobile' => null];
        foreach (array_keys($images) as $device) {
            if ($placement->size($device) === null) {
                continue;
            }
            $images[$device] = $this->presenter->media($ids[$device] ?? null);
            if ($images[$device] === null) {
                return null;
            }
        }

        return $images;
    }

    /** @return array{images: array<string, mixed>, target: string}|null */
    private function house(AdPlacement $placement): ?array
    {
        $house = $placement->houseAd;
        if (! $house instanceof HouseAd || ! $house->is_active) {
            return null;
        }
        $images = ['desktop' => null, 'mobile' => null];
        foreach (array_keys($images) as $device) {
            if ($placement->size($device) !== null) {
                $images[$device] = $this->presenter->media($house->{$device.'_media_id'});
            }
        }

        return array_filter($images) === [] ? null : ['images' => $images, 'target' => $house->target_path];
    }

    private function remember(string $key, \Closure $build): mixed
    {
        $version = (int) Cache::get('ads:version', 0);

        return Cache::remember("ads:v{$version}:{$key}", $this->ttl(), $build);
    }

    /** Seconds until the board changes by itself: the next start or end of an active campaign, at most TTL. */
    private function ttl(): int
    {
        $next = DB::selectOne("SELECT extract(epoch FROM min(t) - now()) AS s FROM (
            SELECT starts_at AS t FROM ad_campaigns WHERE status = 'active' AND starts_at > now()
            UNION ALL SELECT ends_at FROM ad_campaigns WHERE status = 'active' AND ends_at > now()) x")->s;

        return $next === null ? self::TTL : max(1, min(self::TTL, (int) ceil((float) $next)));
    }
}
