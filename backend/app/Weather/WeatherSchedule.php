<?php

namespace App\Weather;

use Illuminate\Support\Facades\DB;

/**
 * Every visible city is fetched once an hour (12.2), spread over the hour so the calls do not all leave at once:
 * with n cities, the i-th (by id, so reordering the list does not move anyone) is due at minute ⌊i × 60 / n⌋.
 */
final class WeatherSchedule
{
    /** @return list<int> the ids of the cities due at this minute of the hour */
    public static function due(int $minute): array
    {
        $ids = DB::table('weather_locations')->where('is_active', true)->orderBy('city_id')->pluck('city_id')->map(fn ($id) => (int) $id)->all();
        $count = count($ids);

        return array_values(array_filter($ids, fn (int $id, int $i) => intdiv($i * 60, $count) === $minute, ARRAY_FILTER_USE_BOTH));
    }
}
