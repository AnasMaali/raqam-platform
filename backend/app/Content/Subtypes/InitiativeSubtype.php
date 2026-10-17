<?php

namespace App\Content\Subtypes;

use App\Filament\Forms\OrganizationSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/** An economic initiative (5.5). */
final class InitiativeSubtype extends Subtype
{
    public function kind(): string
    {
        return 'initiative';
    }

    public function table(): string
    {
        return 'initiatives';
    }

    public function rules(): array
    {
        return [
            'initiative_name' => ['required', 'string', 'max:160'],
            'organizer_organization_id' => ['required', 'integer', 'min:1', 'exists:organizations,id'],
            'status' => ['required', 'in:ongoing,ended'],
            'url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }

    public function required(): array
    {
        return ['initiative_name', 'organizer_organization_id', 'status'];
    }

    public function messages(): array
    {
        return ['organizer_organization_id.exists' => 'الجهة غير موجودة.', 'status.in' => 'الحالة غير صحيحة.', 'url.url' => 'الرابط غير صحيح، ويجب أن يبدأ بـ https://', 'initiative_name.max' => 'الاسم أطول من 160 حرفاً.'];
    }

    public function fields(): array
    {
        return [
            TextInput::make('initiative_name')->label('اسم المبادرة')->maxLength(160),
            OrganizationSelect::make('organizer_organization_id')->label('الجهة المنظمة'),
            Select::make('status')->label('الحالة')->native(false)->options(['ongoing' => 'جارية', 'ended' => 'منتهية'])->default('ongoing'),
            TextInput::make('url')->label('رابط المبادرة')->url()->maxLength(2048)->extraInputAttributes(['dir' => 'ltr']),
        ];
    }
}
