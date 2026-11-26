<?php

namespace App\Panel;

use App\Ads\AdAlerts;
use App\Integrations\ProviderAlerts;
use App\Media\ImageProcessor;
use App\Video\YouTubeQuota;
use Illuminate\Support\Facades\DB;

/**
 * The warnings shown at the top of the panel's home. Each one says what is wrong and what to do about it.
 */
final class PanelAlerts
{
    /** @return list<string> every warning: what the editors act on, then the system's */
    public static function all(): array
    {
        return [...self::editorial(), ...self::system()];
    }

    /**
     * What the editors act on, shown to everyone at the top of the home: a video used in posts that YouTube no longer
     * shows, a campaign ending within three days (13.9).
     *
     * @return list<string>
     */
    public static function editorial(): array
    {
        return [...self::unavailableVideos(), ...AdAlerts::ending()];
    }

    /**
     * The system's state, for whoever holds «settings.manage», in the home's system section: the server's picture
     * formats, an outside source that failed three times in a row and has not answered since (11.3, 12.3), the
     * YouTube quota.
     *
     * @return list<string>
     */
    public static function system(): array
    {
        $alerts = [];
        // Development machines may lack AVIF; a production image without it serves heavier pictures.
        if (app()->isProduction() && ! app(ImageProcessor::class)->canEncode('avif')) {
            $alerts[] = 'صيغة AVIF غير مدعومة على هذا السيرفر، فالصور تُقدَّم بـ WebP وJPEG فقط وبحجم أكبر. صورة Docker لازم يكون فيها GD مبني مع libavif.';
        }
        if (app(YouTubeQuota::class)->nearLimit()) {
            $alerts[] = 'استُهلك جزء كبير من حصة يوتيوب اليومية ('.app(YouTubeQuota::class)->used().' وحدة)؛ قد يتوقف جلب البيانات التلقائي حتى الغد.';
        }

        return [...$alerts, ...ProviderAlerts::current()];
    }

    /** @return list<string> */
    private static function unavailableVideos(): array
    {
        $alerts = [];
        $reasons = ['private' => 'صار خاصاً', 'removed' => 'حُذف من يوتيوب'];

        $bad = DB::table('youtube_videos')->join('videos', 'videos.id', '=', 'youtube_videos.video_id')->whereNull('videos.deleted_at')
            ->whereIn('youtube_videos.availability', array_keys($reasons))->orderBy('videos.id')->limit(10)
            ->get(['videos.id', 'videos.display_title', 'youtube_videos.source_title', 'youtube_videos.availability']);

        foreach ($bad as $video) {
            $uses = DB::table('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')->where('video_usage.video_id', $video->id)
                ->distinct()->limit(5)->pluck('posts.title')->all();
            $alerts[] = 'الفيديو «'.($video->display_title ?: $video->source_title).'» '.$reasons[$video->availability].' على يوتيوب'
                .($uses === [] ? '.' : ' وهو مستخدم في: '.implode('، ', $uses).'.').' بدّله أو أزله من المواد.';
        }

        return $alerts;
    }
}
