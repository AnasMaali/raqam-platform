<?php

namespace App\Support;

/**
 * Text a visitor typed, made safe to show anywhere: control characters and the bidirectional overrides, embeddings
 * and isolates (U+202A–U+202E, U+2066–U+2069) go. Those can flip what an admin sees ("exe.gpj" for "jpg.exe") in the
 * inbox and in e-mails; the marks that Arabic text needs (U+200E, U+200F) stay.
 */
final class VisitorText
{
    public static function clean(string $text, bool $keepNewlines = true): string
    {
        $text = (string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text);
        $pattern = $keepNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';

        return trim((string) preg_replace(['/ {2,}/u'], ' ', (string) preg_replace($pattern, $keepNewlines ? '' : ' ', $text)));
    }
}
