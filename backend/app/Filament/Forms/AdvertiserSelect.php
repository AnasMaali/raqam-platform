<?php

namespace App\Filament\Forms;

use App\Models\Advertiser;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Picks an advertiser by its company's name, or adds one (a company, new or existing, and a contact) without leaving the form. */
final class AdvertiserSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)->native(false)->searchable()
            ->getSearchResultsUsing(fn (string $search) => Advertiser::query()->join('organizations', 'organizations.id', '=', 'advertisers.organization_id')
                ->where('organizations.name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderBy('organizations.name')->limit(30)
                ->pluck('organizations.name', 'advertisers.id')->all())
            ->getOptionLabelUsing(fn ($value) => MediaSelect::isId($value) ? Advertiser::query()->with('organization')->find((int) $value)?->organization?->name : null)
            ->createOptionForm(self::fields())
            ->createOptionUsing(fn (array $data) => self::create($data)->id);
    }

    /** @return list<Field> */
    public static function fields(): array
    {
        return [
            OrganizationSelect::make('organization_id')->label('الجهة (الشركة)')->required(),
            TextInput::make('contact_name')->label('اسم المسؤول')->maxLength(120),
            TextInput::make('contact_phone')->label('هاتف المسؤول')->tel()->rule('regex:/^\+[1-9][0-9]{6,14}$/')
                ->validationMessages(['regex' => 'بالصيغة الدولية، مثال: +970599123456'])->helperText('بالصيغة الدولية، مثال: +970599123456'),
            TextInput::make('contact_email')->label('بريد المسؤول')->email()->maxLength(254),
        ];
    }

    /** Checked again here, not only in the form: this is where it is written. One advertiser per company: picking a company that already is one picks it. */
    public static function create(array $data): Advertiser
    {
        $existing = MediaSelect::isId($data['organization_id'] ?? null) ? Advertiser::query()->where('organization_id', (int) $data['organization_id'])->first() : null;

        return $existing ?? DB::transaction(fn () => Advertiser::query()->create(self::validate($data)));
    }

    /** @return array<string, mixed> */
    public static function validate(array $data, ?int $ignore = null): array
    {
        return Validator::make($data, [
            'organization_id' => ['required', 'integer', 'max:999999999999999999', 'exists:organizations,id', Rule::unique('advertisers', 'organization_id')->ignore($ignore)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['organization_id.unique' => 'هذه الجهة مسجّلة كمعلن مسبقاً.', 'contact_phone.regex' => 'رقم الهاتف بالصيغة الدولية، مثال: +970599123456', 'contact_email.email' => 'البريد الإلكتروني غير صحيح.'])->validate();
    }
}
