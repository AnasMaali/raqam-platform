<?php

namespace App\Video;

use Illuminate\Support\Facades\Cache;

/**
 * Counts the units spent on the YouTube Data API today (decision 8.14). The daily quota resets at midnight
 * Pacific time, so the day is named in that zone. Calls stop at the budget, leaving room under the free limit.
 */
final class YouTubeQuota
{
    private function key(): string
    {
        return 'youtube:units:'.now('America/Los_Angeles')->format('Y-m-d');
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function allows(int $units): bool
    {
        return $this->used() + $units <= (int) config('raqam.youtube.quota_budget');
    }

    public function spend(int $units): void
    {
        Cache::add($this->key(), 0, now()->addDays(2));
        Cache::increment($this->key(), $units);
    }

    public function nearLimit(): bool
    {
        return $this->used() >= (int) config('raqam.youtube.quota_warn');
    }
}
