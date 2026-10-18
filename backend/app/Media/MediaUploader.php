<?php

namespace App\Media;

use App\Jobs\GenerateMediaVariants;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * Takes a file from the outside world into the library (decisions 9.1, 9.2, 9.7 – 9.9).
 *
 * Whatever arrives is read from its bytes, not its name; images are decoded and written again (which
 * drops EXIF, GPS and anything appended), shrunk to a sane size and kept under the stored-size limit;
 * SVG goes through a sanitiser; the file is stored under a random name with the extension of its real
 * type. Public images must come with alt text.
 */
final class MediaUploader
{
    public function __construct(
        private FileInspector $inspector,
        private ImageProcessor $images,
        private SvgSanitizer $svg,
        private MediaStorage $storage,
    ) {}

    /**
     * @param  array{alt_text?: ?string, caption?: ?string, credit?: ?string, visibility?: string, focal_x?: ?float, focal_y?: ?float}  $attributes
     *
     * @throws MediaRejected
     */
    public function store(string $path, string $originalName, array $attributes, ?int $uploadedBy): MediaUpload
    {
        $visibility = $attributes['visibility'] ?? 'public';
        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new MediaRejected('نوع الظهور غير صحيح.');
        }

        $bytes = $this->read($path);
        $mime = $this->inspector->mimeOf($bytes);
        $this->checkUploadSize(strlen($bytes), $mime);

        $alt = $this->clean($attributes['alt_text'] ?? null, 250);
        if (str_starts_with($mime, 'image/') && $visibility === 'public' && $alt === null) {
            throw new MediaRejected('النص البديل (Alt) إجباري للصور العامة.', 'alt_text');
        }

        [$bytes, $mime, $width, $height] = $this->process($bytes, $mime);

        $checksum = hash('sha256', $bytes);
        $existing = MediaAsset::query()->where('checksum_sha256', $checksum)->where('visibility', $visibility)->first();
        if ($existing !== null) {
            return new MediaUpload($existing, duplicate: true);
        }

        $now = now();
        $disk = $this->storage->activeDisk();
        $filePath = $this->storage->newBasePath($now).'.'.$this->inspector->extensionFor($mime);

        $asset = (new MediaAsset)->forceFill([
            'visibility' => $visibility,
            'disk' => $disk,
            'path' => $filePath,
            'original_name' => $this->displayName($originalName, $mime),
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'checksum_sha256' => $checksum,
            'width' => $width,
            'height' => $height,
            'alt_text' => $alt,
            'caption' => $this->clean($attributes['caption'] ?? null, 500),
            'credit' => $this->clean($attributes['credit'] ?? null, 200),
            'focal_x' => $attributes['focal_x'] ?? null,
            'focal_y' => $attributes['focal_y'] ?? null,
            'uploaded_by' => $uploadedBy,
        ]);

        $this->storage->disk($disk, $visibility)->put($filePath, $bytes);

        try {
            DB::transaction(fn () => $asset->save());
        } catch (\Throwable $e) {
            // The row was refused (a CHECK, say): do not leave the file behind.
            $this->storage->disk($disk, $visibility)->delete($filePath);
            throw $e;
        }

        if ($asset->isRaster()) {
            GenerateMediaVariants::dispatch($asset->id)->afterCommit();
        }

        return new MediaUpload($asset, duplicate: false);
    }

    /** @throws MediaRejected */
    private function read(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new MediaRejected('الملف فارغ أو غير موجود.');
        }

        $largest = max(config('media.limits.image_upload'), config('media.limits.pdf'), config('media.limits.svg'));
        if ($size > $largest) {
            throw new MediaRejected($this->tooBig($largest));
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new MediaRejected('تعذّرت قراءة الملف.');
        }

        return $bytes;
    }

    /** @throws MediaRejected */
    private function checkUploadSize(int $size, string $mime): void
    {
        $limit = match (true) {
            $mime === FileInspector::PDF => config('media.limits.pdf'),
            $mime === FileInspector::SVG => config('media.limits.svg'),
            default => config('media.limits.image_upload'),
        };

        if ($size > $limit) {
            throw new MediaRejected($this->tooBig($limit));
        }
    }

    private function tooBig(int $limit): string
    {
        return 'حجم الملف أكبر من الحد المسموح ('.round($limit / 1048576).' ميغابايت).';
    }

    /**
     * @return array{0: string, 1: string, 2: ?int, 3: ?int} the bytes to store, their type, width, height
     *
     * @throws MediaRejected
     */
    private function process(string $bytes, string $mime): array
    {
        if ($mime === FileInspector::PDF) {
            return [$bytes, $mime, null, null];
        }

        if ($mime === FileInspector::SVG) {
            return [$this->svg->clean($bytes), $mime, null, null];
        }

        // Decoding a big picture needs room (24 megapixels is about 100 MB in memory, and a resize holds a second copy).
        if ($this->memoryBytes() < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }

        $image = $this->images->limitSide($this->images->decode($bytes, $mime));
        $format = $this->storedFormat($mime, $image);

        [$out, $format] = $this->encodeWithinLimit($image, $format);
        $stored = match ($format) {
            'jpeg' => FileInspector::JPEG,
            'png' => FileInspector::PNG,
            'webp' => FileInspector::WEBP,
            'avif' => FileInspector::AVIF,
        };

        return [$out, $stored, imagesx($image), imagesy($image)];
    }

    private function memoryBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n
        };
    }

    /** An image keeps its format, except that an AVIF the server cannot write again becomes WebP. */
    private function storedFormat(string $mime, \GdImage $image): string
    {
        $format = match ($mime) {
            FileInspector::JPEG => 'jpeg',
            FileInspector::PNG => 'png',
            FileInspector::WEBP => 'webp',
            FileInspector::AVIF => 'avif',
        };

        return $this->images->canEncode($format) ? $format : ($this->images->hasAlpha($image) ? 'webp' : 'jpeg');
    }

    /**
     * @return array{0: string, 1: string} the bytes and the format they ended up in
     *
     * @throws MediaRejected
     */
    private function encodeWithinLimit(\GdImage $image, string $format): array
    {
        $limit = (int) config('media.limits.image_stored');
        $quality = (int) config('media.quality.master');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $out = $this->images->encode($image, $format, $quality);
            if (strlen($out) <= $limit) {
                return [$out, $format];
            }
            if ($format === 'png') {
                // Lossless and too big: a picture without transparency can become a JPEG, otherwise only a smaller one would help.
                if ($this->images->hasAlpha($image)) {
                    break;
                }
                $format = 'jpeg';

                continue;
            }
            $quality -= 10;
        }

        throw new MediaRejected('الصورة كبيرة جداً حتى بعد الضغط، قلّل أبعادها وحاول مجدداً.');
    }

    /** Free text from a form: trimmed, empty means none, cut to the column. */
    private function clean(?string $text, int $max): ?string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** The name shown in the library: the file's own name without any directory, never used on disk. */
    private function displayName(string $name, string $mime): string
    {
        $name = $this->clean(basename(str_replace('\\', '/', $name)), 255) ?? '';

        return $name === '' ? 'file.'.$this->inspector->extensionFor($mime) : $name;
    }
}
