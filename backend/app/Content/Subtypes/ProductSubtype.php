<?php

namespace App\Content\Subtypes;

use App\Filament\Forms\OrganizationSelect;
use Filament\Forms\Components\TextInput;

/** A "Made in Nablus" product (5.3). */
final class ProductSubtype extends Subtype
{
    public function kind(): string
    {
        return 'product';
    }

    public function table(): string
    {
        return 'products';
    }

    public function rules(): array
    {
        return [
            'product_name' => ['required', 'string', 'max:160'],
            'organization_id' => ['required', 'integer', 'min:1', 'exists:organizations,id'],
            'contact_url' => ['nullable', 'url:http,https', 'max:2048'],
            'contact_phone' => ['nullable', 'regex:/^\+[1-9][0-9]{6,14}$/'],
        ];
    }

    public function required(): array
    {
        return ['product_name', 'organization_id'];
    }

    public function messages(): array
    {
        return [
            'organization_id.exists' => 'الجهة غير موجودة.', 'contact_url.url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://',
            'contact_phone.regex' => 'رقم الهاتف بالصيغة الدولية، مثال: +970599123456', 'product_name.max' => 'الاسم أطول من 160 حرفاً.',
        ];
    }

    public function fields(): array
    {
        return [
            TextInput::make('product_name')->label('اسم المنتج')->maxLength(160),
            OrganizationSelect::make('organization_id')->label('المصنع / الجهة'),
            TextInput::make('contact_url')->label('رابط التواصل')->url()->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('contact_phone')->label('هاتف التواصل')->tel()->extraInputAttributes(['dir' => 'ltr'])->helperText('بالصيغة الدولية، مثال: +970599123456'),
        ];
    }
}
