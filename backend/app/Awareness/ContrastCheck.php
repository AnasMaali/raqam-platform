<?php

namespace App\Awareness;

/**
 * WCAG contrast between two colours (decision 7.14). A character's colour is used for its name and its speech
 * bubble on the page background, so it has to be readable there: 4.5 to 1 is the level for ordinary text.
 */
final class ContrastCheck
{
    public const MINIMUM = 4.5;

    public static function ratio(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public static function passes(string $colour, ?string $background = null): bool
    {
        return self::ratio($colour, $background ?? self::background()) >= self::MINIMUM;
    }

    /** The page background the colours are checked against. */
    public static function background(): string
    {
        return (string) config('raqam.awareness.background', '#FFFFFF');
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $part) {
            $c = hexdec($part) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
