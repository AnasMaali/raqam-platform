<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin's picture in the panel: the first letter of their name, drawn here as an SVG in a data: address. Filament's
 * own default asks ui-avatars.com for it, which hands every admin's name to an outside service and needs that origin
 * in the panel's content security policy (decision after QA-8).
 */
final class InitialsAvatar implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));
        // The first letter or digit, after any leading punctuation ("[SYSTEM] Admin").
        $letter = preg_match('/[\p{L}\p{N}]/u', $name, $m) ? $m[0] : '?';
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.htmlspecialchars($background, ENT_QUOTES).'"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="30" fill="#FFFFFF">'
            .htmlspecialchars($letter, ENT_QUOTES | ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
