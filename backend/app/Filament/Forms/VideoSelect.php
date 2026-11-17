<?php

namespace App\Filament\Forms;

use App\Models\Video;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/** A picker for one video of the library, searched by its title (decisions 8.5, 8.10). */
final class VideoSelect
{
    /**
     * $siblings: inside a repeater, the path from this field to the repeater's list ("../../videos"): the titles of all
     * its videos are then read in one query, not one per item (QA-5), and kept for the rest of the request.
     */
    public static function make(string $name, ?string $siblings = null): Select
    {
        return Select::make($name)->searchable()->native(false)
            ->getSearchResultsUsing(fn (string $search) => Video::query()->search($search)->with('youtube')->latest('id')->limit(30)->get()
                ->mapWithKeys(fn (Video $v) => [$v->id => $v->title()])->all())
            ->getOptionLabelUsing(fn ($value, Get $get) => ListLabels::of('videos', $value, $get, $name, $siblings,
                fn (array $ids) => Video::query()->with('youtube')->whereKey($ids)->get()->mapWithKeys(fn (Video $v) => [$v->id => $v->title()])->all()))
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && $value !== '' && (! MediaSelect::isId($value) || ! Video::query()->whereKey((int) $value)->exists())) {
                    $fail('اختر فيديو موجوداً في مكتبة الفيديو.');
                }
            });
    }
}
