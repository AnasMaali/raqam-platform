<?php

namespace App\Filament;

/**
 * The contrast of every pair of colours the panel draws text and controls with, in its light and dark themes, measured
 * from PanelTheme (WCAG 2.1 relative luminance). DESIGN.md's panel table is exactly `table()`; PanelThemeTest fails when
 * a pair drops below its ratio or when the table no longer matches. To write the table again:
 *   php artisan tinker --execute 'echo App\Filament\PanelContrast::table();'
 */
final class PanelContrast
{
    /**
     * [what it is, needs, [light text, light background], [dark text, dark background]]; a colour is a scale and its
     * step ("gray.950"), `white`, or `line-strong` (the theme's control border).
     *
     * @var list<array{string, float, array{string, string}, array{string, string}}>
     */
    public const PAIRS = [
        ['Text', 4.5, ['gray.950', 'white'], ['white', 'gray.900']],
        ['Secondary text', 4.5, ['gray.600', 'white'], ['gray.400', 'gray.900']],
        ['Helper text and descriptions on the page', 4.5, ['gray.500', 'gray.50'], ['gray.400', 'gray.950']],
        ['A placeholder', 4.5, ['gray.500', 'white'], ['gray.400', 'gray.900']],
        ['Links, a group\'s name in the sidebar', 4.5, ['primary.600', 'gray.50'], ['primary.400', 'gray.950']],
        ['The open screen in the sidebar', 4.5, ['primary.700', 'primary.50'], ['primary.400', 'primary.950']],
        ['A primary button', 4.5, ['white', 'primary.600'], ['white', 'primary.600']],
        ['A primary button under the pointer', 4.5, ['white', 'primary.500'], ['white', 'primary.500']],
        ['A danger button', 4.5, ['white', 'danger.600'], ['white', 'danger.600']],
        ['A danger button under the pointer', 4.5, ['white', 'danger.500'], ['white', 'danger.500']],
        ['A brand badge', 4.5, ['primary.700', 'primary.50'], ['primary.400', 'gray.900']],
        ['A success badge (rise)', 4.5, ['success.700', 'success.50'], ['success.300', 'gray.900']],
        ['A danger badge (fall)', 4.5, ['danger.700', 'danger.50'], ['danger.200', 'gray.900']],
        ['A warning badge, the banner offering a browser copy back', 4.5, ['warning.700', 'warning.50'], ['warning.400', 'gray.900']],
        ['A warning in text', 4.5, ['warning.600', 'white'], ['warning.400', 'gray.950']],
        ['A control\'s border on a surface', 3.0, ['line-strong', 'white'], ['line-strong', 'gray.900']],
        ['A control\'s border on the page', 3.0, ['line-strong', 'gray.50'], ['line-strong', 'gray.950']],
        ['The focus ring', 3.0, ['primary.600', 'white'], ['primary.400', 'gray.900']],
    ];

    /** @return list<array{use: string, needs: float, light: float, dark: float}> */
    public static function measure(): array
    {
        return array_map(fn (array $pair) => [
            'use' => $pair[0],
            'needs' => $pair[1],
            'light' => self::ratio(self::color($pair[2][0], 'light'), self::color($pair[2][1], 'light')),
            'dark' => self::ratio(self::color($pair[3][0], 'dark'), self::color($pair[3][1], 'dark')),
        ], self::PAIRS);
    }

    public static function table(): string
    {
        $rows = array_map(
            fn (array $r) => sprintf('| %s | %.2f | %.2f | %s |', $r['use'], $r['light'], $r['dark'], rtrim(rtrim(number_format($r['needs'], 1), '0'), '.')),
            self::measure(),
        );

        return implode("\n", ['| Pair | Light | Dark | Needs |', '|---|---|---|---|', ...$rows]);
    }

    public static function color(string $name, string $theme): string
    {
        if ($name === 'white') {
            return '#FFFFFF';
        }
        if ($name === 'line-strong') {
            return PanelTheme::LINE_STRONG[$theme];
        }
        [$scale, $step] = explode('.', $name);

        return PanelTheme::colors()[$scale][(int) $step];
    }

    public static function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [self::luminance($a), self::luminance($b)];
        if ($lo > $hi) {
            [$hi, $lo] = [$lo, $hi];
        }

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');

        return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
    }
}
