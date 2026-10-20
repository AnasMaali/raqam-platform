<?php

namespace App\Console;

use App\Video\AvailabilityCheck;
use App\Video\YouTubeUnavailable;
use Illuminate\Console\Command;

class CheckVideosCommand extends Command
{
    protected $signature = 'videos:check-availability';

    protected $description = 'Check that the YouTube videos of the library are still public (weekly)';

    public function handle(AvailabilityCheck $check): int
    {
        try {
            $result = $check->run();
        } catch (YouTubeUnavailable $e) {
            $this->warn('Skipped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Checked {$result['checked']} video(s), {$result['changed']} changed.");

        return self::SUCCESS;
    }
}
