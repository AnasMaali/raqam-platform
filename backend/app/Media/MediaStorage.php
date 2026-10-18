<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The one place that knows which Laravel disk a media file lives on (decision 9.10). Everything else asks
 * for a file by the asset it belongs to, so moving from this server to R2 is a setting, not a code change.
 */
final class MediaStorage
{
    /** The disk new uploads are written to: "local" or "r2". */
    public function activeDisk(): string
    {
        $disk = (string) config('media.disk');

        if (! isset(config('media.disks')[$disk])) {
            throw new \InvalidArgumentException('MEDIA_DISK must be one of: '.implode(', ', array_keys(config('media.disks'))));
        }

        return $disk;
    }

    public function disk(string $disk, string $visibility): Filesystem
    {
        $name = config("media.disks.{$disk}.{$visibility}");

        if ($name === null) {
            throw new \InvalidArgumentException("No media disk for {$disk}/{$visibility}");
        }

        return Storage::disk($name);
    }

    public function diskOf(MediaAsset $asset): Filesystem
    {
        return $this->disk($asset->disk, $asset->visibility);
    }

    public function read(MediaAsset $asset): string
    {
        return $this->diskOf($asset)->get($asset->path);
    }

    /** Where the files of an asset go: a random base name, so nothing can be guessed or overwritten. */
    public function newBasePath(\DateTimeInterface $at): string
    {
        return $at->format('Y/m').'/'.bin2hex(random_bytes(20));
    }

    /**
     * The address of a file. Public files have a plain URL; a private file has none, only a link that
     * expires (and, on this server, is signed).
     */
    public function url(MediaAsset $asset, ?string $path = null): string
    {
        $path ??= $asset->path;
        $disk = $this->diskOf($asset);

        if ($asset->visibility === 'private') {
            return $disk->temporaryUrl($path, now()->addMinutes((int) config('media.private_url_minutes')));
        }

        return $disk->url($path);
    }

    public function delete(MediaAsset $asset, string ...$paths): void
    {
        $this->diskOf($asset)->delete($paths ?: [$asset->path]);
    }
}
