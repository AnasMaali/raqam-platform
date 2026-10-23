<?php

namespace App\Ads;

use App\Audit\AuditLogger;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saves a campaign with its slots and pictures in one transaction (decision 13.3), after checking everything the
 * database cannot: an https address only, at least one slot, a picture for every device its slots have a size for,
 * public library pictures with the slot's shape and at least its size, a priority of 1–100 and an end in the future.
 * Slot and picture changes land in the audit log next to the campaign's own fields.
 */
final class AdCampaigns
{
    /** How far a picture's shape may be from its slot's (5%): wider and the site would crop or letterbox it. */
    public const SHAPE_TOLERANCE = 0.05;

    /**
     * @param  array<string, mixed>  $data  advertiser_id, name, target_url, starts_at, ends_at (UTC), priority, status, placements, desktop_media_id, mobile_media_id
     *
     * @throws AdRejected
     */
    public function save(?AdCampaign $campaign, array $data, ?int $userId): AdCampaign
    {
        $clean = $this->check($campaign, $data);

        return DB::transaction(function () use ($campaign, $clean, $userId) {
            $before = $campaign ? ['placements' => $campaign->placementCodes(), 'creatives' => $campaign->creativeIds()] : ['placements' => [], 'creatives' => []];

            $campaign ??= new AdCampaign(['created_by' => $userId]);
            $campaign->fill(array_intersect_key($clean, array_flip(['advertiser_id', 'name', 'target_url', 'starts_at', 'ends_at', 'priority', 'status'])));
            if ($campaign->exists && $campaign->isDirty('ends_at')) {
                $campaign->ending_alert_sent_at = null;   // a new end gets its own 3-day warning (13.9)
            }
            $campaign->save();

            DB::table('ad_campaign_placements')->where('campaign_id', $campaign->id)->whereNotIn('placement_code', $clean['placements'])->delete();
            DB::table('ad_campaign_placements')->insertOrIgnore(array_map(fn ($code) => ['campaign_id' => $campaign->id, 'placement_code' => $code], $clean['placements']));

            foreach (['desktop', 'mobile'] as $device) {
                $id = $clean[$device.'_media_id'];
                if ($id === null) {
                    DB::table('ad_creatives')->where('campaign_id', $campaign->id)->where('device', $device)->delete();
                } else {
                    DB::table('ad_creatives')->upsert([['campaign_id' => $campaign->id, 'device' => $device, 'media_id' => $id]], ['campaign_id', 'device'], ['media_id']);
                }
            }

            $after = ['placements' => $campaign->placementCodes(), 'creatives' => $campaign->creativeIds()];
            $changes = [];
            if ($before['placements'] !== $after['placements']) {
                $changes['placements'] = [implode(',', $before['placements']) ?: null, implode(',', $after['placements'])];
            }
            foreach (['desktop', 'mobile'] as $device) {
                if (($before['creatives'][$device] ?? null) !== ($after['creatives'][$device] ?? null)) {
                    $changes['creative_'.$device] = [$before['creatives'][$device] ?? null, $after['creatives'][$device] ?? null];
                }
            }
            if ($changes !== []) {
                AuditLogger::record('update', 'ad_campaigns', $campaign->id, $changes, $userId);
            }
            if ($changes !== [] || $campaign->wasRecentlyCreated || $campaign->wasChanged()) {
                AdBoard::changed('campaign');
            }

            return $campaign->refresh();
        });
    }

    /** A campaign with counted views or clicks keeps its report: it can be paused or left to end, not deleted. */
    public function delete(AdCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            DB::table('ad_campaigns')->where('id', $campaign->id)->lockForUpdate()->first();
            if (DB::table('ad_stats_daily')->where('campaign_id', $campaign->id)->exists()) {
                throw new AdRejected('لهذه الحملة مشاهدات أو نقرات محسوبة، فلا تُحذف حتى يبقى تقريرها. أوقفها بدلاً من ذلك.');
            }
            $campaign->delete();
            AdBoard::changed('campaign');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function check(?AdCampaign $campaign, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new AdRejected('اسم الحملة مطلوب، حتى 160 حرفاً.', 'name');
        }
        $advertiser = $data['advertiser_id'] ?? null;
        if (! self::isId($advertiser) || ! Advertiser::query()->whereKey((int) $advertiser)->exists()) {
            throw new AdRejected('اختر المعلن.', 'advertiser_id');
        }
        $url = trim((string) ($data['target_url'] ?? ''));
        if (($problem = AdTargetUrl::problem($url)) !== null) {
            throw new AdRejected($problem, 'target_url');
        }

        $starts = self::time($data['starts_at'] ?? null) ?? throw new AdRejected('اختر وقت البداية.', 'starts_at');
        $ends = self::time($data['ends_at'] ?? null) ?? throw new AdRejected('اختر وقت النهاية.', 'ends_at');
        if ($ends <= $starts) {
            throw new AdRejected('وقت النهاية يجب أن يكون بعد البداية.', 'ends_at');
        }
        // An old campaign keeps its past end; a new end must still be ahead (a campaign that ended before it began is a typo).
        $endChanged = $campaign === null || ! $campaign->ends_at->equalTo($ends);
        if ($endChanged && $ends <= CarbonImmutable::now()) {
            throw new AdRejected('وقت النهاية مضى؛ اختر وقتاً في المستقبل.', 'ends_at');
        }

        $priority = $data['priority'] ?? null;
        if (! is_numeric($priority) || (string) (int) $priority !== trim((string) $priority) || (int) $priority < 1 || (int) $priority > 100) {
            throw new AdRejected('الأولوية رقم صحيح من 1 إلى 100.', 'priority');
        }
        $status = (string) ($data['status'] ?? '');
        if (! array_key_exists($status, AdCampaign::STATUSES)) {
            throw new AdRejected('اختر حالة الحملة.', 'status');
        }

        $codes = array_values(array_unique(array_map('strval', (array) ($data['placements'] ?? []))));
        $placements = AdPlacement::query()->whereIn('code', $codes ?: [''])->get()->keyBy('code');
        if ($codes === [] || $placements->count() !== count($codes)) {
            throw new AdRejected('اختر مكاناً واحداً على الأقل للحملة.', 'placements');
        }
        sort($codes);

        $media = [];
        foreach (['desktop' => 'الكمبيوتر', 'mobile' => 'الجوال'] as $device => $label) {
            $value = $data[$device.'_media_id'] ?? null;
            $needed = $placements->filter(fn (AdPlacement $p) => $p->size($device) !== null);
            if ($value === null || $value === '') {
                if ($needed->isNotEmpty()) {
                    throw new AdRejected("صورة {$label} مطلوبة لمكان «{$needed->first()->name_ar}».", $device.'_media_id');
                }
                $media[$device.'_media_id'] = null;

                continue;
            }
            $asset = self::isId($value) ? MediaAsset::query()->publicImages()->find((int) $value) : null;
            if ($asset === null || ! $asset->isRaster() || ! $asset->width || ! $asset->height) {
                throw new AdRejected("صورة {$label}: اختر صورة عامة (JPG أو PNG أو WebP أو AVIF) من مكتبة الوسائط.", $device.'_media_id');
            }
            foreach ($needed as $placement) {
                if (($problem = self::fit($asset, $placement, $device, $label)) !== null) {
                    throw new AdRejected($problem, $device.'_media_id');
                }
            }
            $media[$device.'_media_id'] = $asset->id;
        }

        return ['advertiser_id' => (int) $advertiser, 'name' => $name, 'target_url' => $url, 'starts_at' => $starts, 'ends_at' => $ends,
            'priority' => (int) $priority, 'status' => $status, 'placements' => $codes] + $media;
    }

    /** Null when the picture suits the slot: the same shape (within 5%) and at least the slot's size. */
    public static function fit(MediaAsset $asset, AdPlacement $placement, string $device, string $label): ?string
    {
        $size = $placement->size($device);
        if ($size === null) {
            return null;
        }
        $slot = "{$size['width']}×{$size['height']}";
        $shape = ($asset->width / $asset->height) / ($size['width'] / $size['height']);
        if (abs($shape - 1) > self::SHAPE_TOLERANCE) {
            return "صورة {$label} ({$asset->width}×{$asset->height}) لا تناسب شكل مكان «{$placement->name_ar}» ({$slot}). اختر صورة بنفس النسبة، أو اجعل هذا المكان حملة منفصلة.";
        }
        if ($asset->width < $size['width'] || $asset->height < $size['height']) {
            return "صورة {$label} ({$asset->width}×{$asset->height}) أصغر من مكان «{$placement->name_ar}» ({$slot})، فتظهر غير واضحة.";
        }

        return null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isId(mixed $value): bool
    {
        return is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) <= 18;
    }
}
