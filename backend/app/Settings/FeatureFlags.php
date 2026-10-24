<?php

namespace App\Settings;

use App\Ads\AdBoard;
use App\Ads\AdPlacements;
use App\Models\FeatureFlag;
use App\Services\ServiceBoard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Switches features on and off (21.14). The dependency rule itself is a trigger in the database
 * ("payments need booking"); this turns its refusal into a sentence the admin can act on.
 */
final class FeatureFlags
{
    /** Whether a feature is switched on now; an unknown key is off. Read from the table every time (no cache to go stale). */
    public static function on(string $key): bool
    {
        return (bool) DB::table('feature_flags')->where('key', $key)->value('is_enabled');
    }

    /** @throws FeatureFlagRefused */
    public function set(string $key, bool $enabled, ?int $userId): void
    {
        $flag = FeatureFlag::query()->findOrFail($key);

        if ($flag->is_enabled === $enabled) {
            return;
        }

        try {
            // A refused statement aborts a PostgreSQL transaction; the nested one keeps the caller's usable.
            DB::transaction(function () use ($flag, $enabled, $userId) {
                $flag->update(['is_enabled' => $enabled, 'updated_by' => $userId]);
                // The popup and the mobile bar show only while their switch is on: the ad slots the site gets change.
                if (in_array($flag->key, AdPlacements::SWITCHES, true)) {
                    AdBoard::changed('placement');
                }
                // The booking button of every service, and of the advertise page, follows this switch (14.10).
                if ($flag->key === 'booking') {
                    ServiceBoard::changed(null, everyPage: true);
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw new FeatureFlagRefused($this->explain($flag, $enabled));
            }
            throw $e;
        }
    }

    private function explain(FeatureFlag $flag, bool $enabled): string
    {
        $name = fn (string $key) => '«'.FeatureFlag::query()->whereKey($key)->value('description_ar').'»';

        if ($enabled) {
            return 'لا يمكن تفعيل '.$name($flag->key).' قبل تفعيل '.$name($flag->depends_on).'.';
        }

        $dependents = FeatureFlag::query()->where('depends_on', $flag->key)->where('is_enabled', true)->pluck('key')->map($name);

        return 'لا يمكن تعطيل '.$name($flag->key).' وهناك ميزات مفعّلة تعتمد عليه: '.$dependents->implode('، ').'. عطّلها أولاً.';
    }
}
