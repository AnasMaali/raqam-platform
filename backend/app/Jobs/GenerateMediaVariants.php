<?php

namespace App\Jobs;

use App\Media\VariantGenerator;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds the renditions of an uploaded image in the background (decision 9.3). */
class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $mediaId) {}

    public function handle(VariantGenerator $generator): void
    {
        // Decoding a large picture needs room; this only applies to this worker process.
        @ini_set('memory_limit', '768M');

        $asset = MediaAsset::query()->find($this->mediaId);

        // Deleted or gone before the queue got to it: nothing to do.
        if ($asset !== null) {
            $generator->generate($asset);
        }
    }
}
