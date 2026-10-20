<?php

namespace App\Media;

use Symfony\Component\Process\Process;

/**
 * Reads a short video's real duration, size and codec with ffprobe (decisions 8.11, 9.8). The numbers the
 * uploader claims are never trusted, and the file is handed to ffprobe as an argument list (no shell).
 */
class MediaProbe
{
    /** Codecs every current browser plays inside an MP4. */
    private const PLAYABLE = ['h264', 'vp9', 'av1'];

    public function binary(): string
    {
        return (string) config('media.ffprobe', 'ffprobe');
    }

    public function available(): bool
    {
        $process = new Process([$this->binary(), '-version']);
        try {
            $process->setTimeout(10)->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * @return array{duration: float, width: int, height: int, codec: string}
     *
     * @throws MediaRejected
     */
    public function video(string $path): array
    {
        if (! $this->available()) {
            throw new MediaRejected('أداة ffprobe غير مثبّتة على السيرفر، لا يمكن فحص الفيديو. ثبّتها بالأمر: sudo apt install ffmpeg');
        }

        $process = new Process([$this->binary(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        $process->setTimeout(30)->run();
        $data = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($data)) {
            throw new MediaRejected('تعذّرت قراءة الفيديو، الملف تالف أو ليس MP4 حقيقياً.');
        }

        $format = (string) ($data['format']['format_name'] ?? '');
        if (! str_contains($format, 'mp4')) {
            throw new MediaRejected('محتوى الملف ليس MP4.');
        }

        $stream = null;
        foreach ((array) ($data['streams'] ?? []) as $candidate) {
            if (($candidate['codec_type'] ?? null) === 'video' && ($candidate['disposition']['attached_pic'] ?? 0) !== 1) {
                $stream = $candidate;
                break;
            }
        }
        if ($stream === null) {
            throw new MediaRejected('الملف لا يحتوي على مسار فيديو.');
        }

        $duration = (float) ($data['format']['duration'] ?? $stream['duration'] ?? 0);
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($duration <= 0 || $width < 1 || $height < 1) {
            throw new MediaRejected('تعذّر تحديد مدة الفيديو أو أبعاده.');
        }

        // A phone held upright records sideways pixels plus a rotation flag.
        $rotation = (int) ($stream['tags']['rotate'] ?? 0);
        foreach ((array) ($stream['side_data_list'] ?? []) as $side) {
            $rotation = (int) ($side['rotation'] ?? $rotation);
        }
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $codec = (string) ($stream['codec_name'] ?? '');
        if (! in_array($codec, self::PLAYABLE, true)) {
            throw new MediaRejected('ترميز الفيديو ('.($codec ?: 'غير معروف').') لا تشغّله المتصفحات. صدّره بترميز H.264.');
        }

        return ['duration' => round($duration, 2), 'width' => $width, 'height' => $height, 'codec' => $codec];
    }
}
