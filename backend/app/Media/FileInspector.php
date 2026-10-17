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

    private const EXTENSIONS = [
        self::JPEG => 'jpg', self::PNG => 'png', self::WEBP => 'webp', self::AVIF => 'avif',
        self::SVG => 'svg', self::PDF => 'pdf',
    ];

    /** @throws MediaRejected */
    public function mimeOf(string $bytes): string
    {
        $mime = $this->sniff($bytes);

        if ($mime === null) {
            throw new MediaRejected($this->isKnownButNotYetSupported($bytes)
                ? 'هذا النوع من الملفات غير مدعوم في مكتبة الوسائط حالياً.'
                : 'نوع الملف غير مسموح. المسموح: صور JPG وPNG وWebP وAVIF وSVG، وملفات PDF.');
        }

        // A second opinion from libmagic: if it recognises the content as something else, refuse.
        $magic = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($magic !== false && $this->contradicts($mime, $magic)) {
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
            $this->looksLikeSvg($bytes) => self::SVG,
            default => null,
        };
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $head = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', substr($bytes, 0, 4096)));

        return (bool) preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $head);
    }

    private function isKnownButNotYetSupported(string $bytes): bool
    {
        // MP4 (short clips, decision 8.11) and Rive files arrive with the video and awareness work.
        return (strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp') || str_starts_with($bytes, 'RIVE');
    }

    private function contradicts(string $sniffed, string $magic): bool
    {
        if ($sniffed === self::SVG) {
            return ! in_array($magic, [self::SVG, 'text/xml', 'application/xml', 'text/plain', 'text/html'], true);
        }

        // Older libmagic builds do not know AVIF and call it something generic.
        if ($sniffed === self::AVIF) {
            return str_starts_with($magic, 'text/') || str_contains($magic, 'php');
        }

        return $magic !== $sniffed;
    }
}
