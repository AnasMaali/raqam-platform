<?php

use App\Filament\PanelContrast;
use App\Filament\PanelTheme;
use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\View\Components\ButtonComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The panel's identity (DESIGN.md, "The panel"): Raqam's colours with their measured contrast, Alexandria and the
 * logos copied from the site and kept identical, the theme built by Vite, and controls whose border is seen.
 */

function panelThemeRepo(string $path): string
{
    return base_path('../'.$path);
}

beforeEach(function () {
    $this->url = '/'.config('raqam.admin_path');
});

test('every pair of colours the panel draws with reaches its ratio, in light and dark', function () {
    foreach (PanelContrast::measure() as $pair) {
        expect(round($pair['light'], 2))->toBeGreaterThanOrEqual($pair['needs'], "{$pair['use']} (light)")
            ->and(round($pair['dark'], 2))->toBeGreaterThanOrEqual($pair['needs'], "{$pair['use']} (dark)");
    }
});

test('DESIGN.md holds exactly the contrast these colours give', function () {
    $design = file_get_contents(panelThemeRepo('DESIGN.md'));

    expect($design)->toContain("<!-- panel-contrast:start -->\n".PanelContrast::table()."\n<!-- panel-contrast:end -->");
});

test('each scale is pinned to its tokens where Filament reads them, and the cyan is in none', function () {
    $tokens = ['primary' => [600 => '#124FFF', 400 => '#89A7FF', 50 => '#EEF2FF'], 'gray' => [600 => '#535A68', 400 => '#A7AEBC', 50 => '#F4F6FA', 950 => '#0B0E14', 500 => '#646B79'],
        'success' => [600 => '#0B7A43', 400 => '#4CC38A'], 'danger' => [600 => '#C0322B', 400 => '#FF8F87'], 'warning' => [600 => '#8A5700', 400 => '#F2B84B']];
    foreach ($tokens as $scale => $steps) {
        foreach ($steps as $step => $hex) {
            expect(PanelTheme::colors()[$scale][$step])->toBe($hex, "{$scale}.{$step}");
        }
    }
    expect(PanelTheme::colors()['info'])->toBe(PanelTheme::PRIMARY);
    foreach (PanelTheme::colors() as $scale) {
        expect(array_map('strtoupper', $scale))->not->toContain('#21CEEA');
    }
});

test('a filled button keeps white text on the brand blue and on the danger red, also under the pointer', function () {
    // The page first: in a test, calling ButtonComponent before the panel's first request made that request draw
    // Filament's default shades (a request in a running panel is not affected).
    expect($this->get($this->url.'/login')->getContent())->toContain('fi-color-primary fi-bg-color-600 hover:fi-bg-color-500 dark:fi-bg-color-600');

    // Filament picks a filled button's shades and text by contrast; step 500 is set so that it keeps 600 with white.
    foreach (['primary' => PanelTheme::PRIMARY, 'danger' => PanelTheme::DANGER] as $name => $scale) {
        $map = ButtonComponent::make()->getColorMap(array_map(fn (string $hex) => Color::convertToOklch($hex), $scale));

        expect($map)->toMatchArray(['bg' => 600, 'hover:bg' => 500, 'dark:bg' => 600, 'text' => 0, 'hover:text' => 0, 'dark:text' => 0], $name);
    }
});

test('the theme\'s control border is the same line-strong as PanelTheme, in both themes', function () {
    $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
    preg_match('~:root\s*\{[^}]*--raqam-line-strong:\s*(#[0-9a-f]{6})~i', $css, $light);
    preg_match('~:root\.dark\s*\{[^}]*--raqam-line-strong:\s*(#[0-9a-f]{6})~i', $css, $dark);

    expect(strtoupper($light[1] ?? ''))->toBe(PanelTheme::LINE_STRONG['light'])
        ->and(strtoupper($dark[1] ?? ''))->toBe(PanelTheme::LINE_STRONG['dark']);
});

test('Alexandria and the logos are the site\'s own files, unchanged', function () {
    $copies = [
        'backend/resources/fonts/alexandria/alexandria-arabic-latin.woff2' => 'frontend/src/fonts/alexandria-arabic-latin.woff2',
        'backend/resources/fonts/alexandria/OFL.txt' => 'frontend/src/fonts/OFL.txt',
    ];
    foreach (['logo-ar-header.svg', 'logo-ar-header-white.svg', 'logo-ar-vertical.svg', 'logo-ar-vertical-white.svg', 'mark.svg'] as $logo) {
        $copies["backend/public/brand/{$logo}"] = "frontend/public/brand/{$logo}";
    }
    foreach ($copies as $copy => $source) {
        expect(hash_file('sha256', panelThemeRepo($copy)))->toBe(hash_file('sha256', panelThemeRepo($source)), $copy);
    }
});

test('the sign-in page is the brand\'s: the vertical logo, the mark as the icon, the theme from this site, the two steps said', function () {
    $html = $this->get($this->url.'/login')->assertOk()->getContent();

    expect($html)->toContain('/brand/logo-ar-vertical.svg', '/brand/logo-ar-vertical-white.svg', '/brand/mark.svg', '/build/assets/theme-')
        ->toContain('بعد كلمة المرور يصلك رمز على بريدك الإلكتروني.')
        ->toContain("--font-family: 'Alexandria'")
        ->not->toContain('logo-ar-header')
        ->not->toContain('panel-font.css')
        ->not->toMatch('~(fonts\.googleapis|fonts\.gstatic|fonts\.bunny|cdn\.jsdelivr|unpkg|cdnjs)~i');
});

test('inside the panel the header logo stands in the sidebar, white on dark', function () {
    $admin = User::factory()->staff()->create();

    $this->actingAs($admin)->followingRedirects()->get($this->url)->assertOk()
        ->assertSee('/brand/logo-ar-header.svg', false)->assertSee('/brand/logo-ar-header-white.svg', false)
        ->assertDontSee('/brand/logo-ar-vertical', false);
});

test('the built theme declares Alexandria from this site and Raqam\'s radii', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']));

    expect($css)->toMatch('~@font-face\s*\{[^}]*font-family:\s*"?Alexandria"?\s*;[^}]*src:\s*url\("?/build/assets/alexandria-arabic-latin-[^)]+\.woff2"?\)~')
        ->toContain('--radius-lg:.25rem')
        ->toContain('--radius-xl:.5rem')
        ->not->toMatch('~url\("?https?://~');
});
