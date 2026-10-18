<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the responsive renditions of a raster image (decisions 9.3, 9.4, 9.5): thumb, sm, md, lg, xl and
 * the 1200x630 share image, each as AVIF, WebP and JPEG. Safe to run again at any time (it rebuilds
 * everything), which is what changing the focal point does.
 */
final class VariantGenerator
{
    public function __construct(private ImageProcessor $images, private MediaStorage $storage) {}

    /** @return int the number of renditions written */
    public function generate(MediaAsset $asset): int
    {
        if (! $asset->isRaster()) {
            return 0;
        }

        $master = $this->images->decode($this->storage->read($asset), $asset->mime_type);
        $fx = $asset->focal_x ?? 0.5;
        $fy = $asset->focal_y ?? 0.5;

        $formats = [];
        foreach (config('media.formats') as $format) {
            if ($this->images->canEncode($format)) {
                $formats[] = $format;
            } else {
                // Logged, not fatal: the other formats still serve every browser (decision on AVIF support).
                Log::warning("Media {$asset->id}: {$format} is not supported on this server, skipped.");
            }
        }

        $base = preg_replace('/\.[a-z0-9]+$/', '', $asset->path);
        $rows = [];
        $written = [];

        foreach (config('media.variants') as $name => $spec) {
            $image = $this->shape($master, $asset, $name, $spec, $fx, $fy);
            if ($image === null) {
                continue;
            }

            foreach ($formats as $format) {
                $path = "{$base}_{$name}.".($format === 'jpeg' ? 'jpg' : $format);
                $bytes = $this->images->encode($image, $format, (int) config("media.quality.{$format}"));
                $this->storage->diskOf($asset)->put($path, $bytes);

                $written[] = $path;
                $rows[] = [
                    'media_id' => $asset->id, 'variant' => $name, 'format' => $format,
                    'width' => imagesx($image), 'height' => imagesy($image), 'path' => $path, 'size_bytes' => strlen($bytes),
                ];
            }
        }

        $this->replaceRows($asset, $rows, $written);

        return count($rows);
    }

    /** @return ?\GdImage null when this size would be larger than the original */
    private function shape(\GdImage $master, MediaAsset $asset, string $name, array $spec, float $fx, float $fy): ?\GdImage
    {
        if (isset($spec['height'])) {
            // thumb and og are crops to an exact shape, always made.
            return $this->images->cover($master, $spec['width'], $spec['height'], $fx, $fy);
        }

        if ($spec['width'] > $asset->width) {
            return null;
        }

        return $this->images->toWidth($master, $spec['width']);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function replaceRows(MediaAsset $asset, array $rows, array $written): void
    {
        $old = DB::table('media_variants')->where('media_id', $asset->id)->pluck('path')->all();

        DB::transaction(function () use ($asset, $rows) {
            DB::table('media_variants')->where('media_id', $asset->id)->delete();
            DB::table('media_variants')->insert($rows);
        });

        // Files of renditions that no longer exist (a format that was unavailable before, a size that no longer applies).
        $stale = array_values(array_diff($old, $written));
        if ($stale !== []) {
            $this->storage->diskOf($asset)->delete($stale);
        }
    }
}
