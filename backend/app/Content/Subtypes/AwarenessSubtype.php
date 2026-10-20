<?php

namespace App\Content\Subtypes;

use App\Content\PostRuleViolation;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

/**
 * An awareness card (5.7, 7.11, 7.15): the character in one of its poses, and the kind of information. A card
 * cannot leave draft without both; the form picks the character first and then one of that character's poses.
 */
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
        return [
            'character_pose_id.exists' => 'الوضعية غير موجودة، اختر الشخصية ثم إحدى وضعياتها.',
            'character_pose_id.required' => 'اختر وضعية الشخصية.',
            'awareness_type_code.exists' => 'نوع المعلومة غير صحيح.',
        ];
    }

    /** The form also needs the character of the saved pose to show the first choice. */
    public function load(int $postId): array
    {
        $values = parent::load($postId);

        if (isset($values['character_pose_id'])) {
            $values['character_id'] = DB::table('character_poses')->where('id', $values['character_pose_id'])->value('character_id');
        }

        return $values;
    }

    public function fields(): array
    {
        return [
            Select::make('character_id')->label('الشخصية')->native(false)->searchable()->live()
                ->options(fn () => DB::table('characters')->where('is_active', true)->orderBy('sort_order')->pluck('name_ar', 'id')->all())
                ->afterStateUpdated(fn (Set $set) => $set('character_pose_id', null))
                ->helperText(fn () => DB::table('characters')->where('is_active', true)->exists() ? null : 'لا شخصيات بعد. أضفها من «شخصيات التوعية» أو شغّل characters:install.'),
            Select::make('character_pose_id')->label('الوضعية')->native(false)
                ->options(fn (Get $get) => ctype_digit((string) $get('character_id')) ? DB::table('character_poses')
                    ->join('pose_types', 'pose_types.code', '=', 'character_poses.pose_type_code')
                    ->where('character_poses.character_id', (int) $get('character_id'))->orderBy('pose_types.sort_order')
                    ->pluck('pose_types.name_ar', 'character_poses.id')->all() : [])
                ->helperText('اختر الشخصية أولاً. إن لم تظهر وضعيات فأضفها من شاشة الشخصية.'),
            Select::make('awareness_type_code')->label('نوع المعلومة')->native(false)
                ->options(fn () => DB::table('awareness_types')->orderBy('sort_order')->pluck('name_ar', 'code')->all()),
        ];
    }

    protected function validate(array $values, int $postId): void
    {
        parent::validate($values, $postId);

        // A pose of a hidden character cannot be picked for a card.
        $active = DB::table('character_poses')->join('characters', 'characters.id', '=', 'character_poses.character_id')
            ->where('character_poses.id', $values['character_pose_id'])->value('characters.is_active');
        if (! $active) {
            throw new PostRuleViolation('هذه الشخصية مخفية، اختر شخصية ظاهرة.', 'awareness.character_pose_id');
        }
    }
}
