<?php

namespace App\Jobs;

use App\Markets\RateFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fetches the official rates in the background (the panel's "fetch now" button), so the page never waits on the source. */
class FetchCurrencyRates implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(RateFetcher $fetcher): void
    {
        $fetcher->run();
    }
}
