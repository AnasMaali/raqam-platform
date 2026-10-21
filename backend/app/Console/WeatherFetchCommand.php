<?php

namespace App\Console;

use App\Weather\WeatherFetcher;
use App\Weather\WeatherSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fetches the weather. Scheduled every minute with --due: only the cities whose minute it is (once an hour each).
 * By hand: one city by its slug, or every visible city.
 */
class WeatherFetchCommand extends Command
{
    protected $signature = 'weather:fetch {city? : slug of one city} {--due : only the cities due this minute}';

    protected $description = 'Fetch the current weather and the forecast from OpenWeather';

    public function handle(WeatherFetcher $fetcher): int
    {
        $ids = match (true) {
            $this->option('due') => WeatherSchedule::due((int) now()->format('i')),
            $this->argument('city') !== null => DB::table('cities')->join('weather_locations', 'weather_locations.city_id', '=', 'cities.id')
                ->where('cities.slug', $this->argument('city'))->pluck('cities.id')->all(),
            default => DB::table('weather_locations')->where('is_active', true)->orderBy('sort_order')->pluck('city_id')->all(),
        };
        if ($this->argument('city') !== null && $ids === []) {
            $this->error('No such city.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($ids as $id) {
            $result = $fetcher->city((int) $id);
            $failed = $failed || ! in_array($result, ['stored', 'unchanged', 'skipped'], true);
            $this->line("{$id}: {$result}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
