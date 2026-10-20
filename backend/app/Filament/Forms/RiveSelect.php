<?php

namespace App\Filament\Forms;

use App\Media\FileInspector;
use App\Models\MediaAsset;
use Filament\Forms\Components\Select;

/** A picker for one Rive animation file of the media library (decision 7.10). */
final class RiveSelect
{
    public static function make(string $name): Select
    {
        $files = fn () => MediaAsset::query()->where('visibility', 'public')->where('mime_type', FileInspector::RIVE);

        return Select::make($name)->searchable()->native(false)
            ->getSearchResultsUsing(fn (string $search) => $files()->search($search)->latest()->limit(30)->pluck('original_name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? $files()->find((int) $value)?->original_name : null)
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) use ($files) {
                if ($value !== null && $value !== '' && (! MediaSelect::isId($value) || ! $files()->whereKey((int) $value)->exists())) {
                    $fail('اختر ملف Rive عاماً من مكتبة الوسائط.');
                }
            })
            ->helperText('ملف Rive يُرفع من مكتبة الوسائط. اختياري: بدونه تظهر الصور الثابتة فقط.');
    }
}
