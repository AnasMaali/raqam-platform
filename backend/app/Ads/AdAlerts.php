<?php

namespace App\Ads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the panel's home says about ads (21.4, 13.9): the campaigns showing now, and a warning for every active campaign
 * that ends within three days, so the advertiser can be asked about renewing in time. The e-mail of 13.9 comes with the
 * alerts phase; it will mark ad_campaigns.ending_alert_sent_at, which nothing here touches.
 */
final class AdAlerts
{
    /** @return list<string> */
    public static function ending(): array
    {
        $days = (int) config('raqam.ads.ending_alert_days');

        return DB::table('ad_campaigns as c')->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('c.status', 'active')->whereRaw('c.ends_at > now()')->whereRaw('c.ends_at <= now() + make_interval(days => ?)', [$days])
            ->orderBy('c.ends_at')->limit(20)->get(['c.name', 'c.ends_at', 'o.name as advertiser'])
            ->map(fn ($c) => 'الحملة «'.$c->name.'» ('.$c->advertiser.') تنتهي '.self::when($c->ends_at).'. تواصل مع المعلن إذا أراد التجديد.')
            ->all();
    }

    /** @return list<array{name: string, advertiser: string, ends_at: string}> */
    public static function running(): array
    {
        return DB::table('ad_campaigns as c')->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('c.status', 'active')->whereRaw('c.starts_at <= now()')->whereRaw('c.ends_at > now()')
            ->orderBy('c.ends_at')->get(['c.name', 'c.ends_at', 'o.name as advertiser'])
            ->map(fn ($c) => ['name' => $c->name, 'advertiser' => $c->advertiser, 'ends_at' => self::when($c->ends_at)])->all();
    }

    private static function when(string $time): string
    {
        return CarbonImmutable::parse($time)->setTimezone('Asia/Hebron')->format('Y-m-d H:i');
    }
}
