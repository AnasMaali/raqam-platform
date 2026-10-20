<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;

/**
 * Fields whose content is Latin text or digits (an address, a link, a phone number, a slug, a code) are
 * written left to right even though the panel is right to left; otherwise the cursor and the punctuation jump around.
 */
final class LtrFields
{
    /** A field name, or the last part of a dotted one (links.facebook), that holds such content. */
    private const NAMES = '/(^|[._])(slug|code|email|phone|url|link|links|youtube_id|hex|color|accent_color|canonical_url|website|whatsapp)$/i';

    public static function register(): void
    {
        TextInput::configureUsing(function (TextInput $input): void {
            $input->extraInputAttributes(
                fn (TextInput $component): array => self::applies($component) ? ['dir' => 'ltr'] : [],
                merge: true,
            );
        });
    }

    public static function applies(TextInput $input): bool
    {
        if ($input->isEmail() || $input->isUrl() || $input->isTel() || $input->isPassword()) {
            return true;
        }

        return preg_match(self::NAMES, (string) $input->getName()) === 1
            || str_starts_with((string) $input->getName(), 'links.')
            || str_starts_with((string) $input->getName(), 'social.');
    }
}
