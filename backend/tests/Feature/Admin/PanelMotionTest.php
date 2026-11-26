<?php

/*
 * The panel's motion (DESIGN.md, "Motion"; panel phase 4): CSS only, every rule of it inside
 * `prefers-reduced-motion: no-preference`, moving only opacity, translate and scale (colours change on hover and focus),
 * never `all`, and quick: 250 ms at most. Asked for less motion, Filament's own base stills it all.
 */

/** The theme's top-level blocks: [the block's prelude ("" for a plain rule), its whole text]. @return list<array{string, string}> */
function themeBlocks(string $css): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $blocks = [];
    $depth = 0;
    $start = 0;
    for ($i = 0, $n = strlen($css); $i < $n; $i++) {
        if ($css[$i] === '{' && $depth++ === 0) {
            $prelude = trim(substr($css, $start, $i - $start));
        } elseif ($css[$i] === '}' && --$depth === 0) {
            $blocks[] = [$prelude, substr($css, $start, $i + 1 - $start)];
            $start = $i + 1;
        } elseif ($css[$i] === ';' && $depth === 0) {
            $start = $i + 1;
        }
    }

    return $blocks;
}

const MOTION_PROPERTIES = ['opacity', 'translate', 'scale', 'color', 'background-color', 'border-color', 'outline-color', 'box-shadow'];

test('every motion rule of the theme sits inside prefers-reduced-motion: no-preference', function () {
    $outside = [];
    foreach (themeBlocks(file_get_contents(resource_path('css/filament/admin/theme.css'))) as [$prelude, $text]) {
        if (preg_match('~(^|[\s;{])(transition|animation)[a-z-]*\s*:|@starting-style|@keyframes~', $text) && $prelude !== '@media (prefers-reduced-motion: no-preference)') {
            $outside[] = $prelude;
        }
    }

    expect($outside)->toBe([]);
});

test('the motion moves only opacity, translate and scale (and colours), never all, in 250 ms at most', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    // The motion block, with the theme's own motion tokens (--raqam-enter, --raqam-ease-out, …) written out.
    preg_match_all('~(--raqam-(?:enter|exit|ease-[a-z]+))\s*:\s*([^;]+);~', $css, $defined);
    $tokens = array_combine(array_map(fn ($name) => "var({$name})", $defined[1]), array_map('trim', $defined[2]));
    expect(array_keys($tokens))->toContain('var(--raqam-enter)', 'var(--raqam-exit)', 'var(--raqam-ease-out)');
    $motion = strtr(collect(themeBlocks($css))->first(fn ($block) => $block[0] === '@media (prefers-reduced-motion: no-preference)')[1], $tokens);

    preg_match_all('~transition-property\s*:\s*([^;]+);~', $motion, $listed);
    preg_match_all('~transition\s*:\s*([^;]+);~', $motion, $short);
    $properties = [];
    foreach ($listed[1] as $list) {
        array_push($properties, ...array_map('trim', explode(',', $list)));
    }
    foreach ($short[1] as $list) {
        foreach (preg_split('~,(?![^(]*\))~', $list) as $part) {   // the commas between transitions, not those inside cubic-bezier()
            $properties[] = strtok(trim($part), ' ');
        }
    }
    expect(array_values(array_diff(array_unique($properties), MOTION_PROPERTIES)))->toBe([]);

    preg_match_all('~(\d+)ms~', $motion, $durations);
    expect(max(array_map('intval', $durations[1])))->toBeLessThanOrEqual(250)
        ->and($motion)->not->toMatch('~cubic-bezier\([^)]*[\s(]1\.[0-9]*[1-9]~');   // no curve that overshoots (a bounce)
});

test('asked for less motion, Filament\'s own base makes every transition and animation near-instant', function () {
    $base = file_get_contents(base_path('vendor/filament/support/resources/css/base.css'));

    expect($base)->toMatch('~prefers-reduced-motion:\s*reduce~')
        ->toContain('transition-duration: 0.01ms !important')->toContain('animation-duration: 0.01ms !important');
});
