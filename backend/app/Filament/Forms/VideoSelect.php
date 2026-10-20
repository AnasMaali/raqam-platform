<?php

namespace App\Filament\Forms;

use App\Models\Video;
use Filament\Forms\Components\Select;

/** A picker for one video of the library, searched by its title (decisions 8.5, 8.10). */
final class VideoSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)->searchable()->native(false)
            ->getSearchResultsUsing(fn (string $search) => Video::query()->search($search)->with('youtube')->latest('id')->limit(30)->get()
                ->mapWithKeys(fn (Video $v) => [$v->id => $v->title()])->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Video::query()->with('youtube')->find((int) $value)?->title() : null)
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && $value !== '' && (! MediaSelect::isId($value) || ! Video::query()->whereKey((int) $value)->exists())) {
                    $fail('اختر فيديو موجوداً في مكتبة الفيديو.');
                }
            });
    }
}
