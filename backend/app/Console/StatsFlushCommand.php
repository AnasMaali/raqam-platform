<?php

namespace App\Console;

use App\Analytics\StatsFlusher;
use Illuminate\Console\Command;

/** Moves the visit counters of today and yesterday from Redis to the daily tables (22.1). Safe to run again at any time. */
class StatsFlushCommand extends Command
{
    protected $signature = 'stats:flush';

    protected $description = 'Write the Redis visit counters of today and yesterday into the daily statistics tables';

    public function handle(StatsFlusher $flusher): int
    {
        $written = $flusher->flush();
        $this->info('Written: '.collect($written)->map(fn (int $n, string $table) => "{$table} {$n}")->implode(', ').'.');

        return self::SUCCESS;
    }
}
