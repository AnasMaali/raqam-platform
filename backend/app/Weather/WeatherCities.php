<?php

namespace App\Weather;

use App\Content\RedirectManager;
use App\Models\City;
use App\Models\WeatherLocation;
use App\Seo\SitePaths;
use App\Support\ArabicSlug;
use App\Support\UniqueSlug;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adding and removing weather cities (decision 12.1). A removed city leaves the weather with its readings and forecast, its
 * page answers 410 (it was indexed, 12.11), and the city itself goes too unless a company still names it. Adding a city of
 * the same name again brings it back at the same address. The default city (Nablus) cannot be removed.
 */
final class WeatherCities
{
    /** @param  array{name_ar: string, latitude: string|float, longitude: string|float, is_active?: bool}  $data */
    public function add(array $data): WeatherLocation
    {
        return DB::transaction(function () use ($data) {
            $name = trim($data['name_ar']);
            // A city kept for a company (its weather removed earlier) comes back with its address.
            $city = City::query()->where('name_ar', $name)->whereDoesntHave('weather')->first();
            if ($city === null) {
                $slug = ArabicSlug::make($name);
                // /weather/cities and /weather/widget are taken by the API's lists.
                $slug = in_array($slug, ['cities', 'widget'], true) ? 'city-'.$slug : $slug;
                $city = City::query()->create(['name_ar' => $name, 'slug' => UniqueSlug::for('cities', $slug, fallback: 'city'),
                    'latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
            } else {
                $city->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
            }
            $location = WeatherLocation::query()->create(['city_id' => $city->id, 'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => (int) WeatherLocation::query()->max('sort_order') + 1]);
            app(RedirectManager::class)->pathsRevived([SitePaths::city($city->slug)]);
            WeatherBoard::changed();

            return $location;
        });
    }

    public function remove(WeatherLocation $location, ?int $userId): void
    {
        if ($location->is_default) {
            throw new InvalidArgumentException('The default city cannot be removed.');
        }
        DB::transaction(function () use ($location, $userId) {
            $city = $location->city;
            $location->delete();   // its readings and forecast go with it (ON DELETE CASCADE)
            app(RedirectManager::class)->pathsGone([SitePaths::city($city->slug)], $userId);
            if (! DB::table('organizations')->where('city_id', $city->id)->exists()) {
                $city->delete();
            }
        });
        WeatherBoard::changed();
    }
}
