<?php

namespace App\Support;

/**
 * Addresses an admin may type where visitors will be sent (menu links, redirects): a path on this site, or a web
 * address. Never a script, never "//host" or "/\host" (which browsers treat as another site).
 */
final class SafeUrl
{
    public const PATTERN = '~^(/(?![/\\\\])[^\s]*|https?://[^\s/][^\s]*)$~';

    public static function isSafe(?string $url): bool
    {
        return $url !== null && strlen($url) <= 500 && preg_match(self::PATTERN, $url) === 1;
    }

    /** A path of this site (starts with a single slash). */
    public static function isPath(?string $url): bool
    {
        return self::isSafe($url) && str_starts_with((string) $url, '/');
    }
}
