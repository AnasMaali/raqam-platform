<?php

namespace App\Video;

use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;
use App\Search\SearchSync;
use Illuminate\Support\Facades\DB;

/**
 * The weekly look at every YouTube video of the library (decision 8.12): did it turn private or get deleted?
 * The answer is kept on the video; the panel's home lists the ones that are no longer playable.
 */
final class AvailabilityCheck
{
    public function __construct(private YouTubeClient $client) {}

    /**
     * @return array{checked: int, changed: int}
     *
     * @throws YouTubeUnavailable when the key is missing or YouTube could not be asked
     */
    public function run(): array
    {
        $checked = 0;
        $changed = 0;

        DB::table('youtube_videos')->join('videos', 'videos.id', '=', 'youtube_videos.video_id')->whereNull('videos.deleted_at')
            ->select('youtube_videos.video_id', 'youtube_videos.youtube_id', 'youtube_videos.availability')
            // By key, not by check time: the rows change their check time while we walk them.
            ->chunkById(50, function ($rows) use (&$checked, &$changed) {
                $found = $this->client->fetch($rows->pluck('youtube_id')->all());

                foreach ($rows as $row) {
                    $now = isset($found[$row->youtube_id]) ? $found[$row->youtube_id]->availability : 'removed';
                    DB::table('youtube_videos')->where('video_id', $row->video_id)->update([
                        'availability' => $now, 'last_checked_at' => DB::raw('now()'),
                    ]);
                    $checked++;
                    if ($now !== $row->availability) {
                        $changed++;
                        SearchSync::queue('videos', [$row->video_id]);   // a private or removed video leaves search
                        if ($now !== 'ok') {
                            self::alert((int) $row->video_id, $now);
                        }
                    }
                }
            }, 'youtube_videos.video_id', 'video_id');

        return ['checked' => $checked, 'changed' => $changed];
    }

    /** A video that turned private or was deleted: the bell and an e-mail for the admins (8.12, 20.4). */
    private static function alert(int $videoId, string $availability): void
    {
        $video = DB::table('videos')->join('youtube_videos', 'youtube_videos.video_id', '=', 'videos.id')->where('videos.id', $videoId)
            ->first(['videos.display_title', 'youtube_videos.source_title']);
        $title = (string) ($video?->display_title ?: $video?->source_title ?: '#'.$videoId);
        $state = $availability === 'private' ? 'صار خاصاً' : 'حُذف';
        $posts = DB::table('video_usage')->join('posts', 'posts.id', '=', 'video_usage.post_id')->where('video_usage.video_id', $videoId)
            ->distinct()->limit(10)->pluck('posts.title')->all();
        $uses = $posts === [] ? 'لا شيء' : implode('، ', $posts);

        AdminAlerts::raise('content', "video:{$videoId}:{$availability}", "الفيديو «{$title}» {$state} على يوتيوب", 'يستعمله: '.$uses,
            "videos/{$videoId}/edit", fn () => new TemplatedEmail('admin_video_unavailable', ['الفيديو' => $title, 'الحالة' => $state, 'المواد' => $uses]));
    }
}
