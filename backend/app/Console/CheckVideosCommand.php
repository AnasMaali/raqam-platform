<?php

namespace App\Console;

use App\Video\AvailabilityCheck;
use App\Video\VideoLibrary;
use App\Video\YouTubeUnavailable;
use Illuminate\Console\Command;

class CheckVideosCommand extends Command
{
    protected $signature = 'videos:check-availability';

    protected $description = 'Check that the YouTube videos of the library are still public (weekly)';

    public function handle(AvailabilityCheck $check, VideoLibrary $library): int
    {
        // Pictures first: they need no key, only the videos' public addresses.
        $pictures = $library->fillMissingThumbnails();
        $pictures > 0 && $this->info("Stored {$pictures} missing picture(s).");

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
