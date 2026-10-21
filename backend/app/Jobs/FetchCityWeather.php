<?php

namespace App\Jobs;

use App\Weather\WeatherFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fetches one city's weather in the background (the panel's "fetch all" button), so the page never waits on OpenWeather. */
class FetchCityWeather implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $cityId) {}

    public function handle(WeatherFetcher $fetcher): void
    {
        $fetcher->city($this->cityId);
    }
}
