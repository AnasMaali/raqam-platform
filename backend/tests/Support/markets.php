<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** Helpers for the currency and weather tests: answers shaped like the real sources. */

/**
 * A Bank of Israel answer (shape checked 2026-10-21). $rates: code => rate text, or code => [rate, unit].
 */
function boiBody(array $rates = [], string $time = '2026-10-20T12:42:27.6546389Z'): string
{
    $rates = $rates ?: ['USD' => '3.106', 'GBP' => '4.1444', 'JPY' => ['1.9632', '100'], 'EUR' => '3.4822', 'JOD' => '4.3812', 'LBP' => ['0.0003', '10'], 'EGP' => '0.057'];
    $items = [];
    foreach ($rates as $code => $rate) {
        [$value, $unit] = is_array($rate) ? $rate : [$rate, '1'];
        // Written by hand so the numbers are exactly the text given, as the bank sends them.
        $items[] = '{"key":"'.$code.'","currentExchangeRate":'.$value.',"currentChange":-0.1286173633440514469453376200,"unit":'.$unit.',"lastUpdate":"'.$time.'"}';
    }

    return '{"exchangeRates":['.implode(',', $items).']}';
}

/** Stands in for the bank; every other address is refused, so a test also proves nothing else is called. */
function fakeBoi(string|Closure $body, int $status = 200): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Sleep::fake();   // the client's retries wait between attempts
    Http::fake(['boi.org.il/PublicApi/GetExchangeRates' => is_string($body) ? Http::response($body, $status, ['Content-Type' => 'application/json']) : $body]);
}

/** OpenWeather's current-weather answer for Nablus (shape of /data/2.5/weather), with any part changed. */
function owCurrent(array $over = []): string
{
    $data = array_replace_recursive([
        'coord' => ['lon' => 35.2544, 'lat' => 32.2211],
        'weather' => [['id' => 800, 'main' => 'Clear', 'description' => 'clear sky', 'icon' => '01d']],
        'base' => 'stations',
        'main' => ['temp' => 24.31, 'feels_like' => 24.02, 'temp_min' => 24.31, 'temp_max' => 24.31, 'pressure' => 1014, 'humidity' => 48],
        'visibility' => 10000,
        'wind' => ['speed' => 3.6, 'deg' => 290, 'gust' => 4.1],
        'clouds' => ['all' => 0],
        'dt' => now()->subMinutes(10)->timestamp,
        'sys' => ['country' => 'PS', 'sunrise' => 1792555000, 'sunset' => 1792596000],
        'timezone' => 10800, 'id' => 282615, 'name' => 'Nablus', 'cod' => 200,
    ], $over);

    return json_encode($data);
}

/**
 * OpenWeather's 5-day / 3-hour forecast (shape of /data/2.5/forecast): 40 slots from the next 3-hour mark.
 * $slot(int $i, int $hour): array lets a test change a slot (Palestine hour given).
 */
function owForecast(?Closure $slot = null, int $count = 40): string
{
    $start = now()->utc()->startOfHour()->addHours(3 - now()->utc()->hour % 3);
    $list = [];
    for ($i = 0; $i < $count; $i++) {
        $at = $start->copy()->addHours(3 * $i);
        $hour = (int) $at->copy()->timezone('Asia/Hebron')->format('G');
        $temp = 15 + ($hour >= 9 && $hour <= 15 ? 10 : 0) + $i % 3;
        $item = ['dt' => $at->timestamp, 'main' => ['temp' => $temp, 'feels_like' => $temp, 'temp_min' => $temp - 1, 'temp_max' => $temp + 1, 'humidity' => 50],
            'weather' => [['id' => $hour >= 9 && $hour <= 15 ? 801 : 800, 'main' => 'Clouds', 'description' => 'few clouds', 'icon' => '02d']],
            'wind' => ['speed' => 2, 'deg' => 200], 'dt_txt' => $at->format('Y-m-d H:i:s')];
        $list[] = $slot ? $slot($item, $i, $hour) : $item;
    }

    return json_encode(['cod' => '200', 'message' => 0, 'cnt' => $count, 'list' => $list, 'city' => ['id' => 282615, 'name' => 'Nablus']]);
}

/** Stands in for OpenWeather; nothing else may be called. A string answers 200, a Closure decides. */
function fakeOpenWeather(string|Closure $current, string|Closure|null $forecast = null): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Sleep::fake();
    $answer = fn ($body) => is_string($body) ? Http::response($body, 200, ['Content-Type' => 'application/json']) : $body;
    Http::fake([
        'api.openweathermap.org/data/2.5/weather*' => $answer($current),
        'api.openweathermap.org/data/2.5/forecast*' => $answer($forecast ?? owForecast()),
    ]);
}
