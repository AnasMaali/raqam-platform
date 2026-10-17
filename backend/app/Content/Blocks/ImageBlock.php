<?php

namespace App\Content\Blocks;

use App\Filament\Forms\MediaSelect;
use App\Media\MediaHtml;
use App\Models\MediaAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/** One picture of the media library with an optional caption of its own. */
final class ImageBlock extends ContentBlock
{
    public static function getId(): string
    {
        return 'image';
    }

    public static function getLabel(): string
    {
        return 'صورة';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedPhoto;
    }

    public static function getPreviewLabel(array $config): string
    {
        return 'صورة: '.(MediaAsset::query()->find((int) ($config['media_id'] ?? 0))?->original_name ?? '—');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading('إدراج صورة')->modalSubmitActionLabel('إدراج')->schema([
            MediaSelect::make('media_id')->label('الصورة')->required(),
            TextInput::make('caption')->label('تعليق (اختياري)')->maxLength(300),
        ]);
    }

    public static function normalize(array $config): ?array
    {
        $id = self::id($config['media_id'] ?? null);
        if ($id === null || ! MediaAsset::query()->publicImages()->whereKey($id)->exists()) {
            return null;
        }

        return array_filter(['media_id' => $id, 'caption' => self::text($config['caption'] ?? null, 300)], fn ($v) => $v !== null);
    }

    public static function references(array $config): array
    {
        return ['media' => [(int) $config['media_id']]];
    }

    public static function render(array $config): string
    {
        $asset = MediaAsset::query()->publicImages()->find((int) ($config['media_id'] ?? 0));
        if ($asset === null) {
            return '';   // the picture is gone: nothing is better than a broken box
        }

        $caption = ($config['caption'] ?? $asset->caption) ? '<figcaption>'.e($config['caption'] ?? $asset->caption).($asset->credit ? ' <small>'.e($asset->credit).'</small>' : '').'</figcaption>' : '';

        return '<figure class="rq-image">'.app(MediaHtml::class)->picture($asset).$caption.'</figure>';
    }
}
