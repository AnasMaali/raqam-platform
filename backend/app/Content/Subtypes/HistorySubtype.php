<?php

namespace App\Content\Subtypes;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/** An entry of the economic history timeline (5.4). */
final class HistorySubtype extends Subtype
{
    public function kind(): string
    {
        return 'history';
    }

    public function table(): string
    {
        return 'history_entries';
    }

    public function rules(): array
    {
        return [
            'era_id' => ['required', 'integer', 'min:1', 'max:32767', 'exists:eras,id'],
            'period_label' => ['nullable', 'string', 'max:80'],
            'year_from' => ['nullable', 'integer', 'min:-32768', 'max:32767'],
            'year_to' => ['nullable', 'integer', 'min:-32768', 'max:32767', 'gte:year_from'],
        ];
    }

    public function required(): array
    {
        return ['era_id'];
    }

    public function messages(): array
    {
        return ['era_id.exists' => 'الحقبة غير موجودة.', 'year_to.gte' => 'سنة النهاية يجب ألا تسبق سنة البداية.', 'period_label.max' => 'العبارة أطول من 80 حرفاً.'];
    }

    public function fields(): array
    {
        return [
            Select::make('era_id')->label('الحقبة')->native(false)->options(fn () => DB::table('eras')->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                ->helperText('الحقب تُدار من «الحقب التاريخية».'),
            TextInput::make('period_label')->label('الفترة (نص)')->maxLength(80)->placeholder('مثال: أواخر القرن التاسع عشر'),
            TextInput::make('year_from')->label('من سنة')->numeric()->integer()->minValue(-32768)->maxValue(32767),
            TextInput::make('year_to')->label('إلى سنة')->numeric()->integer()->minValue(-32768)->maxValue(32767),
        ];
    }
}
