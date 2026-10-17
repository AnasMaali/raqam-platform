<?php

namespace App\Content\Subtypes;

use App\Content\PostRuleViolation;
use App\Filament\Forms\OrganizationSelect;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A success story (5.2): the company, the person, and the highlighted figures (rows, not an array). */
final class StorySubtype extends Subtype
{
    public const MAX_FIGURES = 8;

    public function kind(): string
    {
        return 'story';
    }

    public function table(): string
    {
        return 'success_stories';
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'min:1', 'exists:organizations,id'],
            'person_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function required(): array
    {
        return ['organization_id'];
    }

    public function messages(): array
    {
        return ['organization_id.exists' => 'الجهة غير موجودة.', 'person_name.max' => 'الاسم أطول من 120 حرفاً.'];
    }

    public function fields(): array
    {
        return [
            OrganizationSelect::make('organization_id')->label('الشركة / الجهة'),
            TextInput::make('person_name')->label('اسم الشخص')->maxLength(120),
            Repeater::make('figures')->label('أرقام بارزة')->maxItems(self::MAX_FIGURES)->reorderable()->defaultItems(0)->addActionLabel('إضافة رقم')
                ->itemLabel(fn (array $state) => trim(($state['value'] ?? '').' '.($state['label'] ?? '')))
                ->schema([
                    TextInput::make('value')->label('الرقم')->required()->maxLength(40),
                    TextInput::make('label')->label('الوصف')->required()->maxLength(120),
                ])->columns(2)->columnSpanFull(),
        ];
    }

    public function load(int $postId): array
    {
        return parent::load($postId) + ['figures' => DB::table('success_story_figures')->where('post_id', $postId)->orderBy('sort_order')->get(['value', 'label'])->map(fn ($r) => (array) $r)->all()];
    }

    public function save(int $postId, array $values): array
    {
        $figures = $values['figures'] ?? null;
        $changes = parent::save($postId, $values);

        return $figures === null ? $changes : $this->saveFigures($postId, (array) $figures, $changes);
    }

    protected function saveChildren(int $postId, array $values, array $changes): array
    {
        return $changes;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function saveFigures(int $postId, array $figures, array $changes): array
    {
        $rows = [];
        foreach (array_values($figures) as $i => $figure) {
            $figure = (array) $figure;
            $rows[] = ['value' => trim((string) ($figure['value'] ?? '')), 'label' => trim((string) ($figure['label'] ?? ''))];
        }
        $rows = array_values(array_filter($rows, fn ($r) => $r['value'] !== '' || $r['label'] !== ''));

        try {
            Validator::make(['figures' => $rows], [
                'figures' => ['array', 'max:'.self::MAX_FIGURES],
                'figures.*.value' => ['required', 'string', 'max:40'],
                'figures.*.label' => ['required', 'string', 'max:120'],
            ], ['figures.*.value.required' => 'اكتب الرقم.', 'figures.*.label.required' => 'اكتب وصف الرقم.', 'figures.*.value.max' => 'الرقم أطول من 40 حرفاً.', 'figures.*.label.max' => 'الوصف أطول من 120 حرفاً.', 'figures.max' => 'الحد الأقصى '.self::MAX_FIGURES.' أرقام.'])->validate();
        } catch (ValidationException $e) {
            throw new PostRuleViolation($e->errors()[array_key_first($e->errors())][0], 'story.figures');
        }

        if ($rows !== [] && ! DB::table($this->table())->where('post_id', $postId)->exists()) {
            throw new PostRuleViolation('اختر الشركة قبل إضافة الأرقام.', 'story.organization_id');
        }

        $before = DB::table('success_story_figures')->where('post_id', $postId)->orderBy('sort_order')->get(['value', 'label'])->map(fn ($r) => (array) $r)->all();
        if ($before === $rows) {
            return $changes;
        }

        DB::table('success_story_figures')->where('post_id', $postId)->delete();
        foreach ($rows as $i => $row) {
            DB::table('success_story_figures')->insert(['post_id' => $postId, 'sort_order' => $i] + $row);
        }

        $describe = fn (array $list) => implode('، ', array_map(fn ($r) => $r['value'].' '.$r['label'], $list));
        $changes['story.figures'] = [$describe($before), $describe($rows)];

        return $changes;
    }
}
