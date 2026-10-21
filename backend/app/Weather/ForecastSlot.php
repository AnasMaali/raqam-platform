<?php

namespace App\Weather;

use Carbon\CarbonImmutable;

/** One 3-hour slot of the forecast. */
final readonly class ForecastSlot
{
    public function __construct(
        public CarbonImmutable $at,
        public string $min,
        public string $max,
        public int $condition,
    ) {}

    /** @param array<mixed> $item numbers as text (DecimalJson) */
    public static function read(array $item): ?self
    {
        $main = is_array($item['main'] ?? null) ? $item['main'] : [];
        $at = Reading::unixTime($item['dt'] ?? null, aheadDays: 6);
        $min = Reading::celsius($main['temp_min'] ?? null);
        $max = Reading::celsius($main['temp_max'] ?? null);
        $condition = Reading::integer($item['weather'][0]['id'] ?? null, 100, 999);
        if ($at === null || $min === null || $max === null || $condition === null) {
            return null;
        }

        return new self($at, $min, $max, $condition);
    }
}
