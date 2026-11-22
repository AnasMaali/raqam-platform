<?php

namespace App\Filament;

/**
 * The panel's colours: Filament's scales (50 to 950) built from Raqam's tokens (DESIGN.md, "The panel").
 *
 * Each scale is pinned to its tokens where Filament reads them (600 on light pages, 400 on dark ones) and the steps
 * between are interpolated in OKLCH. Registered here, not only in the theme's CSS, so that Filament keeps choosing the
 * text colour of each button and badge by contrast. `info` is the brand blue: the cyan is the future, never a state.
 * PanelThemeTest measures every pair the panel draws with, in both themes, and compares them with DESIGN.md.
 */
final class PanelTheme
{
    public const PRIMARY = [50 => '#EEF2FF', 100 => '#DBE3FF', 200 => '#C8D5FF', 300 => '#B5C6FF', 400 => '#89A7FF', 500 => '#3468FF', 600 => '#124FFF', 700 => '#113ECB', 800 => '#0E2E99', 900 => '#142661', 950 => '#141B2E'];

    public const GRAY = [50 => '#F4F6FA', 100 => '#E8EAF0', 200 => '#DCDFE6', 300 => '#C1C6D1', 400 => '#A7AEBC', 500 => '#646B79', 600 => '#535A68', 700 => '#3A404C', 800 => '#262C3A', 900 => '#131824', 950 => '#0B0E14'];

    public const SUCCESS = [50 => '#E9F6EF', 100 => '#C5EAD6', 200 => '#A1DDBD', 300 => '#7AD0A4', 400 => '#4CC38A', 500 => '#1A864F', 600 => '#0B7A43', 700 => '#0B6239', 800 => '#0D4A2E', 900 => '#0E3422', 950 => '#0C1F16'];

    public const DANGER = [50 => '#FDEEEC', 100 => '#FFD7D2', 200 => '#FFC0B9', 300 => '#FFA8A0', 400 => '#FF8F87', 500 => '#CD473E', 600 => '#C0322B', 700 => '#982C2D', 800 => '#712529', 900 => '#4C1E21', 950 => '#2A1517'];

    public const WARNING = [50 => '#FDF3E2', 100 => '#FBE5C0', 200 => '#F8D69D', 300 => '#F5C778', 400 => '#F2B84B', 500 => '#BE8629', 600 => '#8A5700', 700 => '#6F4806', 800 => '#55390A', 900 => '#3D2A0B', 950 => '#271C0A'];

    /** The page itself: `ground` (white) and `ground-dark`. */
    public const GROUND = ['light' => '#FFFFFF', 'dark' => '#0B0E14'];

    /** The border of a control (`line-strong`), which must be seen: 3:1. The theme's CSS holds the same two values. */
    public const LINE_STRONG = ['light' => '#7D8491', 'dark' => '#6B7384'];

    /** @return array<string, array<int, string>> */
    public static function colors(): array
    {
        return [
            'primary' => self::PRIMARY,
            'gray' => self::GRAY,
            'success' => self::SUCCESS,
            'danger' => self::DANGER,
            'warning' => self::WARNING,
            'info' => self::PRIMARY,
        ];
    }
}
