<?php

namespace App\Weather;

use App\Integrations\DecimalJson;
use App\Integrations\OutboundHttp;
use App\Integrations\ProviderUnavailable;
use JsonException;

/**
 * OpenWeatherMap's free API (decision 12): current weather and the 5-day / 3-hour forecast for a point, in Celsius.
 *
 * OpenWeather takes its key in the address (`appid=`), not in a header. So the address is never written anywhere:
 * OutboundHttp turns every failure into a fixed sentence before it leaves, and ProviderHealth scrubs again.
 * Without a key nothing is sent and the last stored weather stays.
 *
 * Nothing in an answer is trusted: numbers are read as text and checked against physical ranges, the condition code
 * must be a known one (or fall back to its group), times must be near our own clock.
 */
final class OpenWeatherSource implements WeatherSource
{
    private const MAX_BYTES = 262144;

    /** Forecast slots: the free plan sends 40 (5 days × 8); more is a broken answer. */
    private const MAX_SLOTS = 60;

    public function code(): string
    {
        return 'openweathermap';
    }

    public function configured(): bool
    {
        return (string) config('raqam.weather.key') !== '';
    }

    /** @throws ProviderUnavailable */
    public function current(float|string $lat, float|string $lon): Observation
    {
        $data = $this->get('weather', $lat, $lon);

        return Observation::read($data) ?? throw new ProviderUnavailable('ردّ OpenWeather للطقس الحالي غير صالح.');
    }

    /**
     * @return list<ForecastSlot>
     *
     * @throws ProviderUnavailable
     */
    public function forecast(float|string $lat, float|string $lon): array
    {
        $data = $this->get('forecast', $lat, $lon);
        $list = $data['list'] ?? null;
        if (! is_array($list) || ! array_is_list($list) || $list === [] || count($list) > self::MAX_SLOTS) {
            throw new ProviderUnavailable('ردّ OpenWeather للتوقعات غير صالح.');
        }

        $slots = array_values(array_filter(array_map(fn ($item) => is_array($item) ? ForecastSlot::read($item) : null, $list)));
        if ($slots === []) {
            throw new ProviderUnavailable('ردّ OpenWeather للتوقعات غير صالح.');
        }

        return $slots;
    }

    /** @return array<mixed> */
    private function get(string $endpoint, float|string $lat, float|string $lon): array
    {
        if (! $this->configured()) {
            throw new ProviderUnavailable('مفتاح OpenWeather غير مضبوط.');
        }

        $base = rtrim((string) (config('raqam.weather.api_base') ?: 'https://api.openweathermap.org'), '/');
        $response = OutboundHttp::get('OpenWeather', "{$base}/data/2.5/{$endpoint}", [
            'lat' => (string) $lat, 'lon' => (string) $lon, 'units' => 'metric', 'appid' => (string) config('raqam.weather.key'),
        ], maxBytes: self::MAX_BYTES);

        try {
            return DecimalJson::decode($response->body());
        } catch (JsonException) {
            throw new ProviderUnavailable('ردّ OpenWeather ليس JSON صالحاً.');
        }
    }
}
