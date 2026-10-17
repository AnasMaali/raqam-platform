<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The media trash. A file in use cannot go there: the foreign keys stop a permanent delete, but a move to
 * the trash is only a flag, so the library itself must refuse (decision from the media schema notes).
 */
final class MediaTrash
{
    public function __construct(private MediaUsage $usage, private MediaStorage $storage) {}

    /** @throws MediaInUse */
    public function trash(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            // Lock the row first: a reference being added at this moment waits for us, and we see it.
            MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->first();

            $usages = $this->usage->of($asset);
            if ($usages !== []) {
                throw new MediaInUse($usages);
            }

            $asset->delete();
        });
    }

    public function restore(MediaAsset $asset): void
    {
        $asset->restore();
    }

    /**
     * Removes a trashed file for good, with its renditions.
     *
     * @throws MediaInUse
     */
    public function purge(MediaAsset $asset): void
    {
        if (! $asset->trashed()) {
            throw new \LogicException('Only a file in the trash can be deleted for good.');
        }

        $paths = $asset->variants()->pluck('path')->push($asset->path)->all();

        try {
            DB::transaction(fn () => $asset->forceDelete());
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {   // foreign_key_violation: something still points at it
                throw new MediaInUse($this->usage->of($asset));
            }
            throw $e;
        }

        $this->storage->disk($asset->disk, $asset->visibility)->delete($paths);
    }
}
