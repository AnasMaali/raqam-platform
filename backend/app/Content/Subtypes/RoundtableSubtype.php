<?php

namespace App\Content\Subtypes;

use Filament\Forms\Components\DatePicker;

/** A round-table session (5.6): its date. The title is the post's title and the participants are its guests. */
final class RoundtableSubtype extends Subtype
{
    public function kind(): string
    {
        return 'roundtable';
    }

    public function table(): string
    {
        return 'roundtable_sessions';
    }

    public function rules(): array
    {
        return ['held_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01']];
    }

    public function required(): array
    {
        return ['held_on'];
    }

    public function messages(): array
    {
        return ['held_on.date_format' => 'التاريخ غير صحيح.', 'held_on.after_or_equal' => 'التاريخ غير صحيح.'];
    }

    public function fields(): array
    {
        return [DatePicker::make('held_on')->label('تاريخ الجلسة')->native(false)->displayFormat('Y-m-d')];
    }
}
