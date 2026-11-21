<?php

namespace App\Http;

/**
 * The content security policy of the back end's pages: the panel, its sign-in and the error pages (decision after
 * QA-8). Enforced: a script that got into a page can load no other script and send nothing anywhere but the panel.
 *
 *   - script-src: the panel's own files, its inline scripts (Filament writes them with no nonce) and 'unsafe-eval':
 *     Livewire 3 bundles Alpine's standard build, which runs every expression written in the page (x-data, @click…)
 *     through `new Function`. Livewire 3 has no build without it.
 *   - img-src and media-src: the panel, the pictures it draws itself (data:, blob: for a file chosen before upload),
 *     the media's origins (MEDIA_ORIGINS) and YouTube's pictures, which the video list shows for a video with no
 *     picture of ours (from the address they are fetched from: i.ytimg.com, or the browser tests' stand-in).
 *   - connect-src and worker-src blob:: Filament's file upload (FilePond) reads a chosen file's preview with fetch() from
 *     the blob: address the page made of it, and turns pictures the right way up in a worker made from a blob: address.
 *   - frame-src 'none': the panel embeds nothing (the newsletter's preview is a sandboxed srcdoc frame, which loads
 *     nothing from anywhere).
 *   - Violations are reported to /csp-report (App\Http\Controllers\CspReportController), one line of the log each.
 *
 * The preview page sets its own, stricter policy, which SecurityHeaders keeps.
 */
final class PanelContentPolicy
{
    public static function header(): string
    {
        $media = implode('', array_map(fn ($origin) => " {$origin}", self::mediaOrigins()));
        $youtube = self::origin((string) (config('raqam.youtube.image_base') ?: 'https://i.ytimg.com'));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:{$media}".($youtube ? " {$youtube}" : ''),
            "media-src 'self' blob:{$media}",
            "font-src 'self'",
            "connect-src 'self' blob:",
            "worker-src 'self' blob:",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'report-uri /csp-report',
        ]);
    }

    /** @return list<string> MEDIA_ORIGINS, each as a bare origin; anything that is not an http(s) origin is left out. */
    public static function mediaOrigins(): array
    {
        $values = preg_split('/[\s,]+/', (string) config('raqam.csp.media_origins'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $origins = [];
        foreach ($values as $value) {
            $origin = self::origin($value);
            // An origin only: scheme, host and port (a closing slash is forgiven), nothing a policy would read as more.
            if ($origin !== null && rtrim($value, '/') === $origin) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])
            || ! preg_match('/^[a-z0-9.-]+$/i', $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
