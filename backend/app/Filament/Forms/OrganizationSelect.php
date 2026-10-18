<?php

namespace App\Filament\Forms;

use App\Models\Organization;
use App\Support\UniqueSlug;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Validator;

/** Picks a company or body, or adds a new one without leaving the form. */
final class OrganizationSelect
{
    /** Only http and https: the general "url" rule also lets data://, view-source://, blob:// and gopher:// through. */
    public const WEBSITE_RULES = ['nullable', 'url:http,https', 'max:2048'];

    public static function make(string $name): Select
    {
        return Select::make($name)->native(false)->searchable()
            ->getSearchResultsUsing(fn (string $search) => Organization::query()->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Organization::query()->find((int) $value)?->name : null)
            ->createOptionForm([
                TextInput::make('name')->label('اسم الجهة')->required()->maxLength(160),
                TextInput::make('website')->label('الموقع')->rules(self::WEBSITE_RULES)->validationMessages(['url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://'])->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
            ])
            ->createOptionUsing(fn (array $data) => self::create($data));
    }

    /** Checked again here, not only in the form: this is where the address is written. */
    public static function create(array $data): int
    {
        $data = Validator::make($data, ['name' => ['required', 'string', 'max:160'], 'website' => self::WEBSITE_RULES], ['website.url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://'])->validate();

        return Organization::query()->create([
            'name' => $data['name'], 'website' => ($data['website'] ?? null) ?: null,
            'slug' => UniqueSlug::for('organizations', $data['name'], fallback: 'org'),
        ])->id;
    }
}
