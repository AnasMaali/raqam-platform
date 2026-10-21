<?php

namespace App\Video;

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
                    }
                }
            }, 'youtube_videos.video_id', 'video_id');

        return ['checked' => $checked, 'changed' => $changed];
    }
}
