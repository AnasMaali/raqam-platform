<?php

namespace App\Console;

use App\Panel\SystemWatch;
use Illuminate\Console\Command;

class SystemWatchCommand extends Command
{
    protected $signature = 'system:watch';

    protected $description = 'Every minute: the scheduler beats (the queue worker and /up look at it) and looks at the queue (QA-6)';

    public function handle(): int
    {
        SystemWatch::beat();

        if (($late = SystemWatch::queueLate()) !== null) {
            SystemWatch::queueStopped($late);
            $this->warn("The oldest job has waited {$late} minutes.");
        }

        return self::SUCCESS;
    }
}
