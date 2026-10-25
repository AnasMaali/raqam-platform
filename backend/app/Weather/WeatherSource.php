<?php

namespace App\Weather;

use App\Integrations\ProviderUnavailable;

/** Where the weather comes from (decision 12.13); one per data_providers row of the "weather" service. */
interface WeatherSource
{
    /** The data_providers code. */
    public function code(): string;

    /** Whether it can be called at all (its key is set). A source that is not never opens a connection. */
    public function configured(): bool;

    /** @throws ProviderUnavailable */
    public function current(float|string $lat, float|string $lon): Observation;

    /**
     * @return list<ForecastSlot>
     *
     * @throws ProviderUnavailable
     */
    public function forecast(float|string $lat, float|string $lon): array;
}
