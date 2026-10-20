<?php

namespace App\Media;

use Symfony\Component\Process\Process;

/**
 * Rewrites an uploaded MP4 without any of its metadata (decision 9.8): phones put the GPS position, the device
 * model and the time of recording in the file. Only the first video track and the audio tracks are kept
 * (location is also recorded as separate data tracks), copied as they are, so nothing is re-encoded.
 * Like ffprobe, ffmpeg is told to open plain files only and to read the input as MP4.
 */
class Mp4Cleaner
{
    public function binary(): string
    {
        return (string) config('media.ffmpeg', 'ffmpeg');
    }

    /** @return list<string> */
    public function arguments(string $in, string $out): array
    {
        return [
            $this->binary(), '-nostdin', '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov', '-enable_drefs', '0', '-i', $in,
            '-map', '0:v:0', '-map', '0:a?', '-map_metadata', '-1', '-map_chapters', '-1', '-c', 'copy',
            '-fflags', '+bitexact', '-flags:v', '+bitexact', '-flags:a', '+bitexact', '-movflags', '+faststart', '-f', 'mp4', '-y', $out,
        ];
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
     * @return string the cleaned file's bytes
     *
     * @throws MediaRejected
     */
    public function clean(string $in): string
    {
        if (! $this->available()) {
            throw new MediaRejected('أداة ffmpeg غير مثبّتة على السيرفر، لا يمكن تنظيف الفيديو. ثبّتها بالأمر: sudo apt install ffmpeg');
        }

        $out = tempnam(sys_get_temp_dir(), 'raqam-clean-');
        try {
            $process = new Process($this->arguments($in, $out));
            $process->setTimeout(90)->run();
            if (! $process->isSuccessful() || ($bytes = file_get_contents($out)) === false || $bytes === '') {
                throw new MediaRejected('تعذّرت معالجة الفيديو، الملف تالف أو ليس MP4 حقيقياً.');
            }

            return $bytes;
        } finally {
            @unlink($out);
        }
    }
}
