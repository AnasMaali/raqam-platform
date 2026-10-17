<?php

namespace App\Media;

use App\Models\MediaAsset;

/** The small picture shown in lists: the thumbnail when it exists, the file itself while it is still being made. */
final class MediaThumb
{
    public static function url(?MediaAsset $asset): ?string
    {
        if ($asset === null) {
            return null;
        }

        $storage = app(MediaStorage::class);

        if ($asset->isRaster()) {
            $thumb = $asset->variants()->where('variant', 'thumb')->where('format', 'jpeg')->value('path');

            return $storage->url($asset, $thumb ?? $asset->path);
        }

        return $asset->mime_type === 'image/svg+xml' ? $storage->url($asset) : null;
    }
}
