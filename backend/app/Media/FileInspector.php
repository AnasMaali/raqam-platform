<?php

namespace App\Media;

/**
 * Finds out what a file really is from its bytes. The name, the extension and the type the browser
 * claimed are never consulted (decision 9.8).
 */
final class FileInspector
{
    public const JPEG = 'image/jpeg';

    public const PNG = 'image/png';

    public const WEBP = 'image/webp';

    public const AVIF = 'image/avif';

    public const SVG = 'image/svg+xml';

    public const PDF = 'application/pdf';

    public const MP4 = 'video/mp4';

    public const RIVE = 'application/x-rive';

    /** The "major brand" of a plain MP4 file; QuickTime (.mov) and HEIC/AVIF brands are different things. */
    private const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'dash', 'mmp4', 'M4V '];

    private const EXTENSIONS = [
        self::JPEG => 'jpg', self::PNG => 'png', self::WEBP => 'webp', self::AVIF => 'avif',
        self::SVG => 'svg', self::PDF => 'pdf', self::MP4 => 'mp4', self::RIVE => 'riv',
    ];

    /** @throws MediaRejected */
    public function mimeOf(string $bytes): string
    {
        $mime = $this->sniff($bytes);

        if ($mime === null) {
            throw new MediaRejected('نوع الملف غير مسموح. المسموح: صور JPG وPNG وWebP وAVIF وSVG، وفيديو MP4، وملفات PDF وRive.');
        }

        // A second opinion from libmagic: if it recognises the content as something else, refuse.
        $magic = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($magic !== false && $this->contradicts($mime, $magic)) {
            throw new MediaRejected('محتوى الملف لا يطابق نوعه.');
        }

        // An animation file is binary; code smuggled inside it is not an animation.
        if ($mime === self::RIVE && preg_match('/<\?(php|=)|<script|<html/i', $bytes) === 1) {
            throw new MediaRejected('محتوى الملف لا يطابق نوعه.');
        }

        return $mime;
    }

    public function extensionFor(string $mime): string
    {
        return self::EXTENSIONS[$mime];
    }

    private function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => self::JPEG,
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => self::PNG,
            strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::WEBP,
            strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true) => self::AVIF,
            str_starts_with($bytes, '%PDF-') => self::PDF,
            strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), self::MP4_BRANDS, true) => self::MP4,
            $this->looksLikeRive($bytes) => self::RIVE,
            $this->looksLikeSvg($bytes) => self::SVG,
            default => null,
        };
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', substr($bytes, 0, 4096)));

        return (bool) preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head);
    }

    /**
     * A Rive file starts with "RIVE" and three variable-length numbers (major version, minor version, file id).
     * Text that merely begins with those letters is not one: the numbers must be well formed binary.
     */
    private function looksLikeRive(string $bytes): bool
    {
        if (! str_starts_with($bytes, 'RIVE') || strlen($bytes) < 8) {
            return false;
        }

        $offset = 4;
        for ($i = 0; $i < 3; $i++) {
            $length = 0;
            do {
                if ($offset >= strlen($bytes) || ++$length > 5) {
                    return false;
                }
                $byte = ord($bytes[$offset++]);
            } while ($byte & 0x80);
        }

        // The header numbers are small; a printable-text file ("RIVER ...") fails here.
        return ord($bytes[4]) >= 1 && ord($bytes[4]) < 0x20;
    }

    private function contradicts(string $sniffed, string $magic): bool
    {
        if ($sniffed === self::SVG) {
            return ! in_array($magic, [self::SVG, 'text/xml', 'application/xml', 'text/plain', 'text/html'], true);
        }

        if ($sniffed === self::MP4) {
            return ! in_array($magic, ['video/mp4', 'video/x-m4v', 'application/mp4'], true);
        }

        // libmagic knows no Rive; it must at least not see text or an executable script.
        if ($sniffed === self::RIVE) {
            return str_starts_with($magic, 'text/') || str_contains($magic, 'php') || str_contains($magic, 'script');
        }

        // Older libmagic builds do not know AVIF and call it something generic.
        if ($sniffed === self::AVIF) {
            return str_starts_with($magic, 'text/') || str_contains($magic, 'php');
        }

        return $magic !== $sniffed;
    }
}
