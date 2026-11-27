<?php

namespace App\Ads;

use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;
use App\Panel\PanelDate;
use Illuminate\Support\Facades\DB;

/**
 * What the panel's home says about ads (21.4, 13.9): the campaigns showing now, and a warning for every active campaign
 * that ends within three days, so the advertiser can be asked about renewing in time; and the e-mail of 13.9, sent once
 * per campaign and end (ads:ending-alerts, hourly).
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
            ->map(fn ($c) => 'الحملة «'.$c->name.'» ('.$c->advertiser.') تنتهي '.PanelDate::text($c->ends_at).'. تواصل مع المعلن إذا أراد التجديد.')
            ->all();
    }

    /** @return list<array{name: string, advertiser: string, ends_at: string}> */
    public static function running(): array
    {
        return DB::table('ad_campaigns as c')->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('c.status', 'active')->whereRaw('c.starts_at <= now()')->whereRaw('c.ends_at > now()')
            ->orderBy('c.ends_at')->get(['c.name', 'c.ends_at', 'o.name as advertiser'])
            ->map(fn ($c) => ['name' => $c->name, 'advertiser' => $c->advertiser, 'ends_at' => $c->ends_at])->all();
    }

    /**
     * The e-mail and the bell for each active campaign that ends within the warning days (13.9, 20.3), once per
     * campaign and end: ending_alert_sent_at is set, and a new end clears it (AdCampaigns::save). Returns how many.
     */
    public static function sendEnding(): int
    {
        $days = (int) config('raqam.ads.ending_alert_days');
        $due = DB::table('ad_campaigns as c')->join('advertisers as a', 'a.id', '=', 'c.advertiser_id')->join('organizations as o', 'o.id', '=', 'a.organization_id')
            ->where('c.status', 'active')->whereNull('c.ending_alert_sent_at')->whereRaw('c.ends_at > now()')
            ->whereRaw('c.ends_at <= now() + make_interval(days => ?)', [$days])->orderBy('c.ends_at')
            ->get(['c.id', 'c.name', 'c.ends_at', 'o.name as advertiser']);

        foreach ($due as $c) {
            // Claimed first: two runs at once cannot both send it.
            $claimed = DB::table('ad_campaigns')->where('id', $c->id)->whereNull('ending_alert_sent_at')->update(['ending_alert_sent_at' => DB::raw('now()')]);
            if ($claimed === 0) {
                continue;
            }
            // The bell's line keeps the date in its order (PanelDate); the e-mail gets the same date as plain text.
            AdminAlerts::raise('ads', 'ad-ending:'.$c->id.':'.$c->ends_at, 'الحملة «'.$c->name.'» تنتهي '.PanelDate::text($c->ends_at), 'المعلن: '.$c->advertiser,
                "ad-campaigns/{$c->id}/edit", fn () => new TemplatedEmail('admin_ad_ending', ['الحملة' => $c->name, 'المعلن' => $c->advertiser, 'النهاية' => PanelDate::format($c->ends_at)]));
        }

        return $due->count();
    }
}
