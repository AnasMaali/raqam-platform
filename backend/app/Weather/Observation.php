<?php

namespace App\Weather;

use Carbon\CarbonImmutable;

/** The weather now at one city, read from an answer and checked against physical limits. */
final readonly class Observation
{
    public function __construct(
        public CarbonImmutable $observedAt,
        public string $temperature,
        public ?string $feelsLike,
        public ?int $humidity,
        public ?string $windKmh,
        public ?int $windDeg,
        public int $condition,
        public bool $isDay,
    ) {}

    /** @param array<mixed> $data numbers as text (DecimalJson) */
    public static function read(array $data): ?self
    {
        $weather = $data['weather'][0] ?? null;
        $main = $data['main'] ?? null;
        if (! is_array($weather) || ! is_array($main)) {
            return null;
        }

        $temperature = Reading::celsius($main['temp'] ?? null);
        $condition = Reading::integer($weather['id'] ?? null, 100, 999);
        $icon = $weather['icon'] ?? null;
        $at = Reading::unixTime($data['dt'] ?? null);
        if ($temperature === null || $condition === null || $at === null || ! is_string($icon) || preg_match('/^\d{2}[dn]$/', $icon) !== 1) {
            return null;
        }

        $wind = is_array($data['wind'] ?? null) ? $data['wind'] : [];

        return new self(
            observedAt: $at,
            temperature: $temperature,
            feelsLike: Reading::celsius($main['feels_like'] ?? null),
            humidity: Reading::integer($main['humidity'] ?? null, 0, 100),
            windKmh: Reading::kmh($wind['speed'] ?? null),
            windDeg: Reading::integer($wind['deg'] ?? null, 0, 360),
            condition: $condition,
            isDay: str_ends_with($icon, 'd'),
        );
    }
}
