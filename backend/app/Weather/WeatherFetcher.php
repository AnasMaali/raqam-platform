<?php

namespace App\Weather;

use App\Integrations\ProviderHealth;
use App\Integrations\ProviderUnavailable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fetches and keeps the weather of one city (decisions 12.2, 12.3, 12.6, 12.7): the conditions now as a new
 * observation, and the forecast, which replaces the city's previous one. Failure keeps whatever is stored and
 * counts in provider_health (the third in a row alerts the admins). Without a key nothing is called at all.
 */
final class WeatherFetcher
{
    public const PROVIDER = 'openweathermap';

    /** A day of the forecast needs this many 3-hour slots to give a fair low and high; today is kept with fewer. */
    private const SLOTS_PER_DAY = 4;

    /** Groups of the source's condition codes, for a code our table does not know yet. */
    private const GROUP_FALLBACK = [2 => 211, 3 => 301, 5 => 501, 6 => 601, 7 => 741, 8 => 804];

    public function __construct(private OpenWeatherSource $source) {}

    /** @return 'stored'|'unchanged'|'skipped'|string  a string other than those three is the failure */
    public function city(int $cityId): string
    {
        if (! $this->source->configured()) {
            return 'skipped';
        }
        $city = DB::table('cities')->join('weather_locations', 'weather_locations.city_id', '=', 'cities.id')->where('cities.id', $cityId)->first(['cities.id', 'latitude', 'longitude']);
        if ($city === null) {
            return 'skipped';
        }

        try {
            $now = $this->source->current($city->latitude, $city->longitude);
            $slots = $this->source->forecast($city->latitude, $city->longitude);
            $condition = $this->condition($now->condition) ?? throw new ProviderUnavailable('رمز حالة طقس غير معروف ('.$now->condition.').');
        } catch (ProviderUnavailable $e) {
            ProviderHealth::failure(self::PROVIDER, $e->getMessage());

            return $e->getMessage();
        }

        $stored = DB::transaction(function () use ($city, $now, $condition, $slots) {
            $inserted = DB::affectingStatement(
                'INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, feels_like_c, humidity_pct, wind_kmh, wind_deg, condition_code, is_day)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (city_id, observed_at) DO NOTHING',
                [$city->id, self::PROVIDER, $now->observedAt->format('Y-m-d H:i:sP'), $now->temperature, $now->feelsLike, $now->humidity,
                    $now->windKmh, $now->windDeg, $condition, $now->isDay],
            );
            $this->replaceForecast($city->id, $slots);

            return $inserted > 0;
        });

        ProviderHealth::success(self::PROVIDER);
        WeatherBoard::changed();

        return $stored ? 'stored' : 'unchanged';
    }

    /** @param list<ForecastSlot> $slots */
    private function replaceForecast(int $cityId, array $slots): void
    {
        $today = CarbonImmutable::now('Asia/Hebron')->toDateString();
        $days = [];
        foreach ($slots as $slot) {
            $condition = $this->condition($slot->condition);
            if ($condition === null) {
                continue;
            }
            $local = $slot->at->setTimezone('Asia/Hebron');
            $days[$local->toDateString()][] = ['hour' => (int) $local->format('G'), 'min' => $slot->min, 'max' => $slot->max, 'condition' => $condition];
        }

        // Today's low and high gather over the day: late in the evening the forecast has few or no slots of today left.
        $kept = DB::table('weather_forecasts')->where('city_id', $cityId)->where('forecast_date', $today)->first();
        DB::table('weather_forecasts')->where('city_id', $cityId)->delete();
        if ($kept !== null) {
            $days[$today] = [...($days[$today] ?? []), ['hour' => 99, 'min' => $kept->temp_min_c, 'max' => $kept->temp_max_c, 'condition' => (int) $kept->condition_code]];
        }
        foreach ($days as $date => $day) {
            if ($date < $today || ($date > $today && count($day) < self::SLOTS_PER_DAY)) {
                continue;
            }
            // The day's picture is the slot nearest midday; the low and high are over all its slots.
            usort($day, fn ($a, $b) => abs($a['hour'] - 13) <=> abs($b['hour'] - 13));
            DB::table('weather_forecasts')->insert([
                'city_id' => $cityId, 'forecast_date' => $date, 'provider_code' => self::PROVIDER, 'condition_code' => $day[0]['condition'],
                'temp_min_c' => (string) BigDecimal::min(...array_map(fn ($s) => BigDecimal::of($s['min']), $day)),
                'temp_max_c' => (string) BigDecimal::max(...array_map(fn ($s) => BigDecimal::of($s['max']), $day)),
            ]);
        }
    }

    /** A known code, or its group's general one; null for a code outside every group. */
    private function condition(int $code): ?int
    {
        static $known = null;
        $known ??= DB::table('weather_conditions')->pluck('code')->map(fn ($c) => (int) $c)->flip()->all();

        if (isset($known[$code])) {
            return $code;
        }
        $fallback = self::GROUP_FALLBACK[intdiv($code, 100)] ?? null;

        return $fallback !== null && isset($known[$fallback]) ? $fallback : null;
    }
}
