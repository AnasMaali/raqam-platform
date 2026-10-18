<?php

namespace App\Media;

use GdImage;

/**
 * All pixel work, on GD. Every image that enters the library is decoded and written again, so whatever
 * else the file carried (EXIF with a GPS position, a camera serial number, a script appended after the
 * image, an unknown chunk) does not survive (decisions 9.3, 9.5, 9.8).
 */
final class ImageProcessor
{
    /** @var array<string, bool> */
    private static array $probed = [];

    /**
     * Formats we can write. What GD reports is not trusted: a build can list AVIF and still refuse to
     * write it, so each format is tried once on a 1x1 picture and the answer is remembered.
     */
    public function canEncode(string $format): bool
    {
        return self::$probed[$format] ??= $this->probe($format);
    }

    private function probe(string $format): bool
    {
        $function = ['jpeg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp', 'avif' => 'imageavif'][$format] ?? null;
        if ($function === null || ! function_exists($function)) {
            return false;
        }

        $pixel = imagecreatetruecolor(1, 1);
        set_error_handler(fn () => true);
        ob_start();
        try {
            $ok = $function($pixel);
        } catch (\Throwable) {
            $ok = false;
        } finally {
            $out = (string) ob_get_clean();
            restore_error_handler();
        }

        return $ok && $out !== '';
    }

    /**
     * Reads width and height without decoding, so an image that claims to be enormous is refused before
     * it can eat the server's memory.
     *
     * @return array{0: int, 1: int}
     *
     * @throws MediaRejected
     */
    public function dimensions(string $bytes): array
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new MediaRejected('تعذّرت قراءة الصورة، الملف تالف أو غير مدعوم.');
        }

        if ((int) config('media.limits.max_pixels') < $size[0] * $size[1]) {
            throw new MediaRejected('أبعاد الصورة كبيرة جداً، قلّل حجمها وحاول مجدداً.');
        }

        return [$size[0], $size[1]];
    }

    /** @throws MediaRejected */
    public function decode(string $bytes, string $mime): GdImage
    {
        $this->dimensions($bytes);

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new MediaRejected('تعذّرت قراءة الصورة، الملف تالف أو غير مدعوم.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->upright($image, $this->orientation($bytes));
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Shrinks so the longer side is at most `max_side`. */
    public function limitSide(GdImage $image): GdImage
    {
        $max = (int) config('media.limits.max_side');
        $w = imagesx($image);
        $h = imagesy($image);

        if (max($w, $h) <= $max) {
            return $image;
        }

        $scale = $max / max($w, $h);

        return $this->resample($image, (int) round($w * $scale), (int) round($h * $scale), 0, 0, $w, $h);
    }

    /** Same picture scaled to a width, never enlarged by the caller (it decides which widths to ask for). */
    public function toWidth(GdImage $image, int $width): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        return $this->resample($image, $width, max(1, (int) round($h * $width / $w)), 0, 0, $w, $h);
    }

    /**
     * Fills width x height with the part of the picture around the focal point (9.5): the point stays
     * inside the frame as far as the picture's shape allows.
     */
    public function cover(GdImage $image, int $width, int $height, float $fx = 0.5, float $fy = 0.5): GdImage
    {
        $sw = imagesx($image);
        $sh = imagesy($image);
        $scale = max($width / $sw, $height / $sh);
        $cw = min($sw, (int) round($width / $scale));
        $ch = min($sh, (int) round($height / $scale));
        $cx = (int) round(max(0, min($sw - $cw, $fx * $sw - $cw / 2)));
        $cy = (int) round(max(0, min($sh - $ch, $fy * $sh - $ch / 2)));

        return $this->resample($image, $width, $height, $cx, $cy, $cw, $ch);
    }

    /** @throws MediaRejected */
    public function encode(GdImage $image, string $format, int $quality): string
    {
        if (! $this->canEncode($format)) {
            throw new MediaRejected("لا يمكن إنشاء صيغة {$format} على هذا الجهاز.");
        }

        if ($format === 'jpeg') {
            $image = $this->flatten($image);
            imageinterlace($image, true);
        }

        ob_start();
        try {
            $ok = match ($format) {
                'jpeg' => imagejpeg($image, null, $quality),
                'png' => imagepng($image, null, 9),
                'webp' => imagewebp($image, null, $quality),
                'avif' => imageavif($image, null, $quality, 6),
            };
        } finally {
            $out = (string) ob_get_clean();
        }

        if (! $ok || $out === '') {
            throw new MediaRejected('تعذّر حفظ الصورة.');
        }

        return $out;
    }

    public function hasAlpha(GdImage $image): bool
    {
        // Sampling is enough to choose between PNG and JPEG for a photo; a fully scanned check would cost seconds.
        $w = imagesx($image);
        $h = imagesy($image);
        $step = max(1, (int) floor(max($w, $h) / 64));

        for ($x = 0; $x < $w; $x += $step) {
            for ($y = 0; $y < $h; $y += $step) {
                if ((imagecolorat($image, $x, $y) >> 24) & 0x7F) {
                    return true;
                }
            }
        }

        return false;
    }

    /** JPEG cannot hold transparency: put the picture on white. */
    private function flatten(GdImage $image): GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    private function resample(GdImage $src, int $dw, int $dh, int $sx, int $sy, int $sw, int $sh): GdImage
    {
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $sw, $sh);

        return $dst;
    }

    private function orientation(string $jpeg): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg), 'IFD0');

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /** Applies the camera's rotation to the pixels, since the tag that says so is about to be dropped. */
    private function upright(GdImage $image, int $orientation): GdImage
    {
        $image = match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0) ?: $image,
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip(imagerotate($image, -90, 0) ?: $image, IMG_FLIP_HORIZONTAL),
            6 => imagerotate($image, -90, 0) ?: $image,
            7 => $this->flip(imagerotate($image, 90, 0) ?: $image, IMG_FLIP_HORIZONTAL),
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };

        return $image;
    }

    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }
}
