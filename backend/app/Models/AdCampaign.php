<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A campaign (decision 13.3): who, where, the desktop and mobile pictures, the advertiser's address, from when until
 * when, its priority and status. "Ended" is not stored: a campaign has ended once its end time has passed (13.4), and
 * the live_ad_campaigns view is the one place that decides what shows. Saved through App\Ads\AdCampaigns.
 */
class AdCampaign extends Model
{
    use Audited;

    public const STATUSES = ['draft' => 'مسودة', 'active' => 'فعّالة', 'paused' => 'موقوفة'];

    protected $fillable = ['advertiser_id', 'name', 'target_url', 'starts_at', 'ends_at', 'priority', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'priority' => 'integer', 'ending_alert_sent_at' => 'immutable_datetime'];
    }

    public function advertiser()
    {
        return $this->belongsTo(Advertiser::class);
    }

    /** @return list<string> */
    public function placementCodes(): array
    {
        return $this->exists ? DB::table('ad_campaign_placements')->where('campaign_id', $this->id)->orderBy('placement_code')->pluck('placement_code')->all() : [];
    }

    /** @return array<string, int> device => media id */
    public function creativeIds(): array
    {
        return $this->exists ? DB::table('ad_creatives')->where('campaign_id', $this->id)->pluck('media_id', 'device')->map(fn ($id) => (int) $id)->all() : [];
    }

    /** What the campaign is doing right now, in the panel's words. */
    public function state(?Carbon $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->status === 'draft' => 'draft',
            $this->ends_at <= $now => 'ended',
            $this->status === 'paused' => 'paused',
            $this->starts_at > $now => 'scheduled',
            default => 'running',
        };
    }

    public const STATE_LABELS = ['draft' => 'مسودة', 'ended' => 'منتهية', 'paused' => 'موقوفة', 'scheduled' => 'مجدولة', 'running' => 'تعمل الآن'];

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
