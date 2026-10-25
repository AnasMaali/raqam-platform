<?php

namespace App\Filament\Forms;

use App\Models\Organization;
use App\Support\UniqueSlug;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Picks a company or body, or adds a new one without leaving the form. */
final class OrganizationSelect
{
    /** Only http and https: the general "url" rule also lets data://, view-source://, blob:// and gopher:// through. */
    public const WEBSITE_RULES = ['nullable', 'url:http,https', 'max:2048'];

    /** The table's own bounds for a founding year. */
    public const YEARS = [1700, 2200];

    public static function make(string $name): Select
    {
        return Select::make($name)->native(false)->searchable()
            ->getSearchResultsUsing(fn (string $search) => Organization::query()->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Organization::query()->find((int) $value)?->name : null)
            ->createOptionForm(self::fields())
            ->createOptionUsing(fn (array $data) => self::create($data));
    }

    /** The fields of a company (5.2, 5.3): name, website, sector, city and founding year. */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('اسم الجهة')->required()->maxLength(160),
            TextInput::make('website')->label('الموقع')->rules(self::WEBSITE_RULES)->validationMessages(['url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://'])->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('sector_id')->label('القطاع')->native(false)->options(fn () => DB::table('sectors')->orderBy('sort_order')->orderBy('id')->pluck('name_ar', 'id')->all())
                ->helperText('القائمة من شاشة «القطاعات الاقتصادية».'),
            Select::make('city_id')->label('المدينة')->native(false)->searchable()->options(fn () => DB::table('cities')->orderBy('name_ar')->pluck('name_ar', 'id')->all()),
            TextInput::make('founded_year')->label('سنة التأسيس')->numeric()->integer()->minValue(self::YEARS[0])->maxValue((int) date('Y') + 1),
        ];
    }

    /** Checked again here, not only in the form: this is where the address is written. */
    public static function create(array $data): int
    {
        $data = self::clean($data);

        return Organization::query()->create($data + ['slug' => UniqueSlug::for('organizations', $data['name'], fallback: 'org')])->id;
    }

    /** @return array{name: string, website: ?string, sector_id: ?int, city_id: ?int, founded_year: ?int} */
    public static function clean(array $data): array
    {
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:160'], 'website' => self::WEBSITE_RULES,
            'sector_id' => ['nullable', 'integer', 'min:1', 'max:32767', 'exists:sectors,id'],
            'city_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'exists:cities,id'],
            'founded_year' => ['nullable', 'integer', 'min:'.self::YEARS[0], 'max:'.((int) date('Y') + 1)],
        ], [
            'website.url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://', '*.exists' => 'الاختيار غير موجود.',
            'founded_year.min' => 'سنة التأسيس غير صحيحة.', 'founded_year.max' => 'سنة التأسيس غير صحيحة.',
        ])->validate();

        return [
            'name' => $data['name'], 'website' => ($data['website'] ?? null) ?: null,
            'sector_id' => isset($data['sector_id']) ? (int) $data['sector_id'] : null, 'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
            'founded_year' => isset($data['founded_year']) ? (int) $data['founded_year'] : null,
        ];
    }
}
