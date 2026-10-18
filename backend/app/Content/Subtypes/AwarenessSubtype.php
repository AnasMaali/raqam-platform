<?php

namespace App\Content\Subtypes;

use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;

/** An awareness card (5.7, 7.11): the character in a pose, and the kind of information. */
final class AwarenessSubtype extends Subtype
{
    public function kind(): string
    {
        return 'awareness';
    }

    public function table(): string
    {
        return 'awareness_cards';
    }

    public function rules(): array
    {
        return [
            'character_pose_id' => ['required', 'integer', 'min:1', 'exists:character_poses,id'],
            'awareness_type_code' => ['required', 'string', 'exists:awareness_types,code'],
        ];
    }

    public function required(): array
    {
        return ['character_pose_id', 'awareness_type_code'];
    }

    public function messages(): array
    {
        return ['character_pose_id.exists' => 'الشخصية أو الوضعية غير موجودة.', 'awareness_type_code.exists' => 'نوع المعلومة غير صحيح.'];
    }

    public function fields(): array
    {
        return [
            Select::make('character_pose_id')->label('الشخصية والوضعية')->native(false)->searchable()
                ->options(fn () => DB::table('character_poses')->join('characters', 'characters.id', '=', 'character_poses.character_id')
                    ->join('pose_types', 'pose_types.code', '=', 'character_poses.pose_type_code')->orderBy('characters.sort_order')->orderBy('pose_types.sort_order')
                    ->get(['character_poses.id', 'characters.name_ar as character', 'pose_types.name_ar as pose'])->mapWithKeys(fn ($r) => [$r->id => $r->character.' — '.$r->pose])->all())
                ->helperText('الشخصيات ووضعياتها تُضاف مع مرحلة التوعية.'),
            Select::make('awareness_type_code')->label('نوع المعلومة')->native(false)
                ->options(fn () => DB::table('awareness_types')->orderBy('sort_order')->pluck('name_ar', 'code')->all()),
        ];
    }
}
