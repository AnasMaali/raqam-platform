<?php

namespace App\Filament\Forms;

use App\Models\MediaAsset;
use Filament\Forms\Components\Select;

/** A picker for one public image of the media library, with search (decision 9.1). */
final class MediaSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)->searchable()->native(false)
            ->getSearchResultsUsing(fn (string $search) => MediaAsset::query()->publicImages()->search($search)->latest()->limit(30)->pluck('original_name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => self::isId($value) ? MediaAsset::query()->find((int) $value)?->original_name : null)
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                // Whatever the form sent, only a live public picture of the library is accepted.
                if ($value !== null && $value !== '' && (! self::isId($value) || ! MediaAsset::query()->publicImages()->whereKey((int) $value)->exists())) {
                    $fail('اختر صورة عامة موجودة في مكتبة الوسائط.');
                }
            })
            ->helperText('اختر صورة من مكتبة الوسائط. للرفع: مكتبة الوسائط ← رفع ملف.');
    }

    /** A value that can be a bigint key; anything else must never reach a query. */
    public static function isId(mixed $value): bool
    {
        return is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) <= 18;
    }
}
