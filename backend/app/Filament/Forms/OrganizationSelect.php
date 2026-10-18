<?php

namespace App\Filament\Forms;

use App\Models\Organization;
use App\Support\UniqueSlug;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/** Picks a company or body, or adds a new one without leaving the form. */
final class OrganizationSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)->native(false)->searchable()
            ->getSearchResultsUsing(fn (string $search) => Organization::query()->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Organization::query()->find((int) $value)?->name : null)
            ->createOptionForm([
                TextInput::make('name')->label('اسم الجهة')->required()->maxLength(160),
                TextInput::make('website')->label('الموقع')->url()->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
            ])
            ->createOptionUsing(fn (array $data) => Organization::query()->create([
                'name' => $data['name'], 'website' => $data['website'] ?: null,
                'slug' => UniqueSlug::for('organizations', $data['name'], fallback: 'org'),
            ])->id);
    }
}
