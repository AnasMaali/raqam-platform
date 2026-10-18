<?php

namespace App\Content\Subtypes;

use App\Content\PostRuleViolation;
use App\Models\MediaAsset;
use Filament\Forms\Components\Select;

/** An infographic (9.11): the full-size file offered for download (a picture or a PDF). */
final class InfographicSubtype extends Subtype
{
    public function kind(): string
    {
        return 'infographic';
    }

    public function table(): string
    {
        return 'infographics';
    }

    public function rules(): array
    {
        return ['download_media_id' => ['required', 'integer', 'min:1']];
    }

    public function required(): array
    {
        return ['download_media_id'];
    }

    public function fields(): array
    {
        $files = fn () => MediaAsset::query()->where('visibility', 'public')->where(fn ($q) => $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'application/pdf'));

        return [
            Select::make('download_media_id')->label('ملف التحميل')->native(false)->searchable()
                ->getSearchResultsUsing(fn (string $search) => $files()->search($search)->latest()->limit(30)->pluck('original_name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => ctype_digit((string) $value) ? $files()->find((int) $value)?->original_name : null)
                ->helperText('صورة بالحجم الكامل أو ملف PDF من مكتبة الوسائط.'),
        ];
    }

    protected function validate(array $values, int $postId): void
    {
        parent::validate($values, $postId);

        $id = (int) $values['download_media_id'];
        $ok = MediaAsset::query()->where('visibility', 'public')->whereKey($id)
            ->where(fn ($q) => $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'application/pdf'))->exists();

        if (! $ok) {
            throw new PostRuleViolation('اختر صورة عامة أو ملف PDF عاماً من مكتبة الوسائط.', 'infographic.download_media_id');
        }
    }
}
