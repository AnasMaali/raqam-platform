<?php

namespace App\Filament\Forms;

use App\Seo\SeoMetaSaver;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The SEO fields of a form (23.7): empty means "automatic", and each text field shows its length against
 * the size search engines show (the counter turns red when it is too long).
 */
final class SeoSection
{
    /**
     * @param  string|null  $titleFrom  the sibling field whose value stands in when the title is left empty (shown as the placeholder)
     */
    public static function make(string $automatic = 'يُملأ تلقائياً من المحتوى إن تُرك فارغاً.', ?string $titleFrom = null, ?string $descriptionFrom = null): Section
    {
        return Section::make('محركات البحث والمشاركة')->description($automatic)->collapsed()->columns(1)->statePath('seo')->schema([
            TextInput::make('meta_title')->label('عنوان الصفحة')->maxLength(SeoMetaSaver::TITLE_MAX)->live(debounce: 300)
                ->placeholder($titleFrom ? fn (Get $get) => mb_substr((string) $get('../'.$titleFrom), 0, SeoMetaSaver::TITLE_MAX) : null)
                ->hint(fn (?string $state) => self::counter($state, SeoMetaSaver::TITLE_MAX))
                ->hintColor(fn (?string $state) => self::color($state, SeoMetaSaver::TITLE_MAX, 30, 60)),
            Textarea::make('meta_description')->label('وصف الصفحة')->rows(3)->maxLength(SeoMetaSaver::DESCRIPTION_MAX)->live(debounce: 300)
                ->placeholder($descriptionFrom ? fn (Get $get) => mb_substr((string) $get('../'.$descriptionFrom), 0, SeoMetaSaver::DESCRIPTION_MAX) : null)
                ->hint(fn (?string $state) => self::counter($state, SeoMetaSaver::DESCRIPTION_MAX))
                ->hintColor(fn (?string $state) => self::color($state, SeoMetaSaver::DESCRIPTION_MAX, 70, 160)),
            TextInput::make('canonical_url')->label('الرابط الأساسي (Canonical)')->url()->maxLength(2048)
                ->extraInputAttributes(['dir' => 'ltr'])->helperText('اتركه فارغاً إلا إذا كان المحتوى منشوراً في مكان آخر.'),
            MediaSelect::make('share_media_id')->label('صورة المشاركة'),
            Toggle::make('robots_noindex')->label('منع الفهرسة في محركات البحث'),
        ]);
    }

    private static function counter(?string $state, int $max): string
    {
        return mb_strlen((string) $state).' / '.$max;
    }

    private static function color(?string $state, int $max, int $min, int $good): string
    {
        $length = mb_strlen((string) $state);

        return match (true) {
            $length > $max => 'danger',
            $length === 0 => 'gray',
            $length < $min => 'warning',
            default => 'success',
        };
    }
}
