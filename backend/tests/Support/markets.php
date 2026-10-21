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
