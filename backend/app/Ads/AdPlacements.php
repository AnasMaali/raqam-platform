<?php

namespace App\Ads;

use App\Models\AdPlacement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The six ad slots and when each one is on. Four are on unless the admin turns them off (13.1); the popup and the
 * mobile bar also need their feature switch (13.2, 21.14), which is off by default: a slot shows only when its own
 * "enabled" and its switch are both on, so neither screen can turn one on behind the other's back.
 */
final class AdPlacements
{
    /** placement code => feature flag it also needs */
    public const SWITCHES = ['popup' => 'ad_popup', 'mobile_sticky' => 'ad_mobile_sticky'];

    public const ORDER = ['header_leaderboard', 'sidebar_mpu', 'in_article', 'between_sections', 'popup', 'mobile_sticky'];

    /** Sizes an admin may give a slot, in pixels. */
    public const MIN_SIZE = 20;

    public const MAX_SIZE = 2000;

    public static function isOn(AdPlacement $placement, ?array $flags = null): bool
    {
        $flags ??= self::flags();
        $switch = self::SWITCHES[$placement->code] ?? null;

        return $placement->is_enabled && ($switch === null || ($flags[$switch] ?? false));
    }

    /** @return array<string, bool> */
    public static function flags(): array
    {
        return DB::table('feature_flags')->whereIn('key', self::SWITCHES)->pluck('is_enabled', 'key')->map(fn ($v) => (bool) $v)->all();
    }

    /** @return list<string> the codes of the slots that are on now */
    public static function onCodes(): array
    {
        $flags = self::flags();

        return AdPlacement::query()->get()->filter(fn (AdPlacement $p) => self::isOn($p, $flags))->pluck('code')->values()->all();
    }

    /** @return Collection<int, AdPlacement> in the site's order */
    public static function all()
    {
        return AdPlacement::query()->with('houseAd')->get()->sortBy(fn (AdPlacement $p) => array_search($p->code, self::ORDER, true))->values();
    }
}
