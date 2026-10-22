<?php

namespace App\Ads;

use App\Models\AdPlacement;
use App\Models\HouseAd;
use App\Models\MediaAsset;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;

/**
 * Saves a slot's settings and its «أعلن معنا» banner together (decisions 13.1, 13.2, 13.10, 13.11, 13.13): sizes within
 * sensible limits (the mobile bar has no desktop size), the popup's frequency, what an empty slot shows, and a house
 * banner whose pictures suit the slot and whose link stays on this site or goes to an https address.
 */
final class AdSlots
{
    /**
     * @param  array<string, mixed>  $data  name_ar, sizes, is_enabled, empty_behavior, frequency_cap_hours, lazy_load, house_* fields
     *
     * @throws AdRejected
     */
    public function save(AdPlacement $placement, array $data): void
    {
        $name = trim((string) ($data['name_ar'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new AdRejected('اسم المكان مطلوب، حتى 60 حرفاً.', 'name_ar');
        }

        $sizes = [];
        foreach (['desktop', 'mobile'] as $device) {
            $w = $data[$device.'_width'] ?? null;
            $h = $data[$device.'_height'] ?? null;
            if ($device === 'desktop' && $placement->code === 'mobile_sticky') {
                $w = $h = null;   // the bar is a phone thing only
            } elseif (! self::pixels($w) || ! self::pixels($h)) {
                throw new AdRejected('المقاس بالبكسل من '.AdPlacements::MIN_SIZE.' إلى '.AdPlacements::MAX_SIZE.'.', $device.'_width');
            }
            $sizes[$device.'_width'] = $w === null ? null : (int) $w;
            $sizes[$device.'_height'] = $h === null ? null : (int) $h;
        }

        $empty = (string) ($data['empty_behavior'] ?? '');
        if (! in_array($empty, ['house', 'hide'], true)) {
            throw new AdRejected('اختر ما يظهر في المكان الفارغ.', 'empty_behavior');
        }
        $cap = null;
        if ($placement->code === 'popup') {
            $cap = $data['frequency_cap_hours'] ?? null;
            if (! is_numeric($cap) || (string) (int) $cap !== trim((string) $cap) || (int) $cap < 1 || (int) $cap > 720) {
                throw new AdRejected('مدة تكرار النافذة بالساعات من 1 إلى 720 (30 يوماً).', 'frequency_cap_hours');
            }
            $cap = (int) $cap;
        }

        $house = $this->house($placement, $data, $sizes);

        DB::transaction(function () use ($placement, $data, $name, $sizes, $empty, $cap, $house) {
            $placement->fill(['name_ar' => $name, 'is_enabled' => (bool) ($data['is_enabled'] ?? false), 'empty_behavior' => $empty,
                'frequency_cap_hours' => $cap, 'lazy_load' => (bool) ($data['lazy_load'] ?? true)] + $sizes)->save();

            $existing = HouseAd::query()->find($placement->code);
            if ($house === null) {
                $existing?->delete();
            } else {
                ($existing ?? new HouseAd(['placement_code' => $placement->code]))->fill($house)->save();
            }
            AdBoard::changed('placement');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int|null>  $sizes
     * @return array<string, mixed>|null null when the slot has no house banner
     */
    private function house(AdPlacement $placement, array $data, array $sizes): ?array
    {
        $desktop = $data['house_desktop_media_id'] ?? null;
        $mobile = $data['house_mobile_media_id'] ?? null;
        if (blank($desktop) && blank($mobile)) {
            return null;
        }
        $target = trim((string) ($data['house_target_path'] ?? '')) ?: '/advertise';
        if (! SafeUrl::isPath($target) && ! AdTargetUrl::isValid($target)) {
            throw new AdRejected('رابط «أعلن معنا»: مسار في الموقع يبدأ بـ / (مثل /advertise) أو رابط يبدأ بـ https://', 'house_target_path');
        }

        $sized = (new AdPlacement)->forceFill(['code' => $placement->code, 'name_ar' => $placement->name_ar] + $sizes);
        $ids = [];
        foreach (['desktop' => [$desktop, 'الكمبيوتر'], 'mobile' => [$mobile, 'الجوال']] as $device => [$value, $label]) {
            if (blank($value)) {
                $ids[$device.'_media_id'] = null;

                continue;
            }
            $asset = is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) <= 18 ? MediaAsset::query()->publicImages()->find((int) $value) : null;
            if ($asset === null || ! $asset->isRaster() || ! $asset->width) {
                throw new AdRejected("صورة {$label} لـ «أعلن معنا»: اختر صورة عامة من مكتبة الوسائط.", 'house_'.$device.'_media_id');
            }
            if (($problem = AdCampaigns::fit($asset, $sized, $device, $label)) !== null) {
                throw new AdRejected($problem, 'house_'.$device.'_media_id');
            }
            $ids[$device.'_media_id'] = $asset->id;
        }

        return $ids + ['target_path' => $target, 'is_active' => (bool) ($data['house_is_active'] ?? true)];
    }

    private static function pixels(mixed $value): bool
    {
        return is_numeric($value) && (string) (int) $value === trim((string) $value)
            && (int) $value >= AdPlacements::MIN_SIZE && (int) $value <= AdPlacements::MAX_SIZE;
    }
}
