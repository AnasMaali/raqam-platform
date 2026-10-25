<?php

namespace App\Weather;

use App\Integrations\DataProviders;

/**
 * The weather sources the code knows, by data_providers code, and the one the settings point at (12.13). A new source is
 * a class implementing WeatherSource, a line in config raqam.weather.sources and a row in data_providers; switching is a
 * setting, done from the weather screen.
 */
final class WeatherSources
{
    /** @return array<string, class-string<WeatherSource>> data_providers code => class (config raqam.weather.sources) */
    public static function all(): array
    {
        return (array) config('raqam.weather.sources');
    }

    public static function get(string $code): ?WeatherSource
    {
        $class = self::all()[$code] ?? null;

        return $class === null ? null : app($class);
    }

    /** The source in use, or null when none is set or it is unknown to the code. */
    public static function active(): ?WeatherSource
    {
        $code = DataProviders::active('weather');

        return $code === null ? null : self::get($code);
    }

    /** Whether the source in use can be called now. */
    public static function ready(): bool
    {
        return self::active()?->configured() ?? false;
    }
}
