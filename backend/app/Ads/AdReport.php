<?php

namespace App\Ads;

use App\Models\AdCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A campaign's numbers for any period (decision 13.8): views, clicks and click rate per day and per slot, with totals.
 * Days are Palestine dates, as counted. The rate is computed in integers (no float rounding surprises): clicks ÷ views
 * as a percentage with two decimals, or null when there were no views.
 */
final class AdReport
{
    public const MAX_DAYS = 731;

    /**
     * @return array{campaign: AdCampaign, from: string, to: string, days: list<array{date: string, impressions: int, clicks: int, ctr: ?string}>,
     *               placements: list<array{code: string, name: string, impressions: int, clicks: int, ctr: ?string}>,
     *               total: array{impressions: int, clicks: int, ctr: ?string}}
     */
    public function build(AdCampaign $campaign, string $from, string $to): array
    {
        [$from, $to] = self::period($from, $to);

        $rows = DB::table('ad_stats_daily')->where('campaign_id', $campaign->id)->whereBetween('stat_date', [$from, $to])->get();
        $byDay = $rows->groupBy(fn ($r) => substr((string) $r->stat_date, 0, 10));

        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $day = $byDay->get($d->toDateString(), collect());
            $days[] = self::line(['date' => $d->toDateString()], (int) $day->sum('impressions'), (int) $day->sum('clicks'));
        }

        $names = DB::table('ad_placements')->pluck('name_ar', 'code');
        $codes = collect($campaign->placementCodes())->merge($rows->pluck('placement_code'))->unique()
            ->sortBy(fn ($c) => array_search($c, AdPlacements::ORDER, true))->values();
        $placements = $codes->map(fn ($code) => self::line(['code' => $code, 'name' => $names[$code] ?? $code],
            (int) $rows->where('placement_code', $code)->sum('impressions'), (int) $rows->where('placement_code', $code)->sum('clicks')))->all();

        return ['campaign' => $campaign, 'from' => $from, 'to' => $to, 'days' => $days, 'placements' => $placements,
            'total' => self::line([], (int) $rows->sum('impressions'), (int) $rows->sum('clicks'))];
    }

    /** The default period: the campaign's days so far (Palestine dates), at most up to today. @return array{0: string, 1: string} */
    public static function defaultPeriod(AdCampaign $campaign): array
    {
        $today = CarbonImmutable::now('Asia/Hebron')->toDateString();
        $start = $campaign->starts_at->setTimezone('Asia/Hebron')->toDateString();
        $end = $campaign->ends_at->subSecond()->setTimezone('Asia/Hebron')->toDateString();

        return [min($start, $today), min($end, $today)];
    }

    /** @return array{0: string, 1: string} the period, in order and at most MAX_DAYS long */
    public static function period(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new AdRejected('التاريخ غير صحيح.');
            }
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            throw new AdRejected('الفترة طويلة جداً؛ اختر سنتين على الأكثر.');
        }

        return [$from, $to];
    }

    /** Clicks ÷ views as a percentage with two decimals, rounded half up, in integers. */
    public static function ctr(int $impressions, int $clicks): ?string
    {
        if ($impressions <= 0) {
            return null;
        }
        $hundredths = intdiv($clicks * 10000 * 2 + $impressions, $impressions * 2);   // round(clicks / views × 100, 2) × 100

        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param array<string, string> $head */
    private static function line(array $head, int $impressions, int $clicks): array
    {
        return $head + ['impressions' => $impressions, 'clicks' => $clicks, 'ctr' => self::ctr($impressions, $clicks)];
    }
}
