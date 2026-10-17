<?php

namespace App\Content\Blocks;

use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * A video of the video library inside the body. The place is ready (the sanitiser keeps it, the database
 * learns about the use through post_body_videos) but the editor does not offer it until the video phase
 * brings the library, the YouTube link and the player.
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

    public static function availableInEditor(): bool
    {
        return false;
    }

    public static function normalize(array $config): ?array
    {
        $id = self::id($config['video_id'] ?? null);

        return $id !== null && DB::table('videos')->where('id', $id)->exists() ? ['video_id' => $id] : null;
    }

    public static function references(array $config): array
    {
        return ['videos' => [(int) $config['video_id']]];
    }

    public static function render(array $config): string
    {
        return '';   // the player arrives with the video phase
    }
}
