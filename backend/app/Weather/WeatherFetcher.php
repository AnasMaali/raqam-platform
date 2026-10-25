<?php

namespace App\Weather;

use App\Integrations\ProviderHealth;
use App\Integrations\ProviderUnavailable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fetches and keeps the weather of one city (decisions 12.2, 12.3, 12.6, 12.7): the conditions now as a new
 * observation, and the forecast, which replaces the city's previous one. Failure keeps whatever is stored and
 * counts in provider_health (the third in a row alerts the admins). The source is the one the settings point at
 * (12.13, WeatherSources); without one, or without its key, nothing is called at all.
 */
final class WeatherFetcher
{
    /** The source the platform started with; screens that name the source fall back to it. */
    public const PROVIDER = 'openweathermap';

    /** The start of the failure when the source answered but the city's weather could not be stored. */
    public const SAVE_FAILED = 'تعذّر حفظ طقس المدينة';

    /** A day of the forecast needs this many 3-hour slots to give a fair low and high; today is kept with fewer. */
    private const SLOTS_PER_DAY = 4;

    /** Groups of the source's condition codes, for a code our table does not know yet. */
    private const GROUP_FALLBACK = [2 => 211, 3 => 301, 5 => 501, 6 => 601, 7 => 741, 8 => 804];

    /** @return 'stored'|'unchanged'|'skipped'|string  a string other than those three is the failure */
    public function city(int $cityId): string
    {
        $source = WeatherSources::active();
        if ($source === null || ! $source->configured()) {
            return 'skipped';
        }
        $provider = $source->code();
        $city = DB::table('cities')->join('weather_locations', 'weather_locations.city_id', '=', 'cities.id')->where('cities.id', $cityId)->first(['cities.id', 'latitude', 'longitude']);
        if ($city === null) {
            return 'skipped';
        }

        try {
            $now = $source->current($city->latitude, $city->longitude);
            $slots = $source->forecast($city->latitude, $city->longitude);
            $condition = $this->condition($now->condition) ?? throw new ProviderUnavailable('رمز حالة طقس غير معروف ('.$now->condition.').');

            $stored = DB::transaction(function () use ($city, $now, $condition, $slots, $provider) {
                $inserted = DB::affectingStatement(
                    'INSERT INTO weather_observations (city_id, provider_code, observed_at, temperature_c, feels_like_c, humidity_pct, wind_kmh, wind_deg, condition_code, is_day)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (city_id, observed_at) DO NOTHING',
                    [$city->id, $provider, $now->observedAt->format('Y-m-d H:i:sP'), $now->temperature, $now->feelsLike, $now->humidity,
                        $now->windKmh, $now->windDeg, $condition, $now->isDay],
                );
                $this->replaceForecast($city->id, $slots, $provider);

                return $inserted > 0;
            });
        } catch (ProviderUnavailable $e) {
            ProviderHealth::failure($provider, $e->getMessage());

            return $e->getMessage();
        } catch (QueryException $e) {
            // One city's row must not stop the others. The statement and its values go to the error log only.
            report($e);
            $message = self::SAVE_FAILED.' (خطأ في قاعدة البيانات، الرمز '.$e->getCode().').';
            ProviderHealth::failure($provider, $message);

            return $message;
        }

        ProviderHealth::success($provider);
        WeatherBoard::changed();

        return $stored ? 'stored' : 'unchanged';
    }

    /** @param list<ForecastSlot> $slots */
    private function replaceForecast(int $cityId, array $slots, string $provider): void
    {
        $midnight = CarbonImmutable::now('Asia/Hebron')->startOfDay();
        $today = $midnight->toDateString();
        $days = [];
        foreach ($slots as $slot) {
            $condition = $this->condition($slot->condition);
            if ($condition === null) {
                continue;
            }
            $local = $slot->at->setTimezone('Asia/Hebron');
            $days[$local->toDateString()][] = ['hour' => (int) $local->format('G'), 'min' => $slot->min, 'max' => $slot->max, 'condition' => $condition];
        }

        // Today's low and high are what was measured since midnight (Palestine) and what the forecast still has for the
        // rest of the day; the hours that have passed count by their readings, not by what was once forecast for them.
        // Late in the evening no slot of today is left, and the readings alone make the day (its picture stays).
        $seen = DB::table('weather_observations')->where('city_id', $cityId)->where('observed_at', '>=', $midnight->format('Y-m-d H:i:sP'))
            ->selectRaw('min(temperature_c) AS low, max(temperature_c) AS high')->first();
        $picture = DB::table('weather_forecasts')->where('city_id', $cityId)->where('forecast_date', $today)->value('condition_code')
            ?? DB::table('weather_observations')->where('city_id', $cityId)->orderByDesc('observed_at')->value('condition_code');
        DB::table('weather_forecasts')->where('city_id', $cityId)->delete();
        if ($seen->low !== null) {
            $days[$today] = [...($days[$today] ?? []), ['hour' => 99, 'min' => $seen->low, 'max' => $seen->high, 'condition' => (int) $picture]];
        }
        foreach ($days as $date => $day) {
            if ($date < $today || ($date > $today && count($day) < self::SLOTS_PER_DAY)) {
                continue;
            }
            // The day's picture is the slot nearest midday; the low and high are over all its slots.
            usort($day, fn ($a, $b) => abs($a['hour'] - 13) <=> abs($b['hour'] - 13));
            DB::table('weather_forecasts')->insert([
                'city_id' => $cityId, 'forecast_date' => $date, 'provider_code' => $provider, 'condition_code' => $day[0]['condition'],
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
