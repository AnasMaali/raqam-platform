<?php

namespace App\Media;

use App\Models\MediaAsset;

/** The outcome of an upload: the asset, and whether it was already in the library (same file, nothing stored twice). */
final readonly class MediaUpload
{
    public function __construct(public MediaAsset $asset, public bool $duplicate) {}
}
