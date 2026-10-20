<?php

namespace App\Content\Blocks;

use App\Filament\Forms\VideoSelect;
use App\Models\Video;
use App\Video\VideoView;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * A video of the video library inside the body (decisions 8.5, 21.6). Only the reference is kept; the player is
 * built from the library when the page is shown, so a video that turned private disappears from old text by itself.
 * The database learns about the use through post_body_videos.
 */
final class VideoBlock extends ContentBlock
{
    public static function getId(): string
    {
        return 'video';
    }

    public static function getLabel(): string
    {
        return 'فيديو';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedPlayCircle;
    }

    public static function getPreviewLabel(array $config): string
    {
        return 'فيديو: '.(Video::query()->with('youtube')->find((int) ($config['video_id'] ?? 0))?->title() ?: '—');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->modalHeading('إدراج فيديو')->modalSubmitActionLabel('إدراج')->schema([
            VideoSelect::make('video_id')->label('الفيديو')->required()->helperText('من مكتبة الفيديو. لإضافة فيديو جديد: مكتبة الفيديو ← إضافة فيديو.'),
        ]);
    }

    public static function normalize(array $config): ?array
    {
        $id = self::id($config['video_id'] ?? null);

        return $id !== null && Video::query()->whereKey($id)->exists() ? ['video_id' => $id] : null;
    }

    public static function references(array $config): array
    {
        return ['videos' => [(int) $config['video_id']]];
    }

    public static function render(array $config): string
    {
        $video = Video::query()->with(['youtube', 'upload', 'poster'])->find((int) ($config['video_id'] ?? 0));

        return $video === null ? '' : app(VideoView::class)->html($video);
    }
}
