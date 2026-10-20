<?php

namespace App\Video;

use App\Media\MediaStorage;
use App\Models\MediaAsset;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How a video is shown to visitors: the light player markup for an article body (8.6, 8.7), and the data the
 * API gives the website, including the VideoObject with its Clips (8.13). The page loads no YouTube code:
 * the player below is a picture and a link, and the website turns it into a youtube-nocookie player on click.
 */
final class VideoView
{
    public function __construct(private MediaStorage $storage) {}

    /** Can a visitor play it? A private or removed YouTube video shows nothing rather than a broken box. */
    public function playable(Video $video): bool
    {
        return ! $video->trashed() && ($video->source === 'upload' || $video->youtube?->availability === 'ok');
    }

    public function thumbnail(Video $video): ?string
    {
        if ($video->poster_media_id !== null && ($poster = MediaAsset::query()->publicImages()->find($video->poster_media_id))) {
            return $this->storage->url($poster);
        }

        return $video->isYoutube() ? ($video->youtube->thumbnail_url ?: YouTubeUrl::thumbnail($video->youtube->youtube_id)) : null;
    }

    /** The player for an article body. */
    public function html(Video $video): string
    {
        if (! $this->playable($video)) {
            return '';
        }

        $title = e($video->title());
        $thumb = $this->thumbnail($video);
        $chapters = $this->chapters($video);
        // Both attributes are quoted and escaped; nothing here comes from the person's HTML.
        $common = ' data-video-id="'.$video->id.'"'.($video->duration_seconds ? ' data-duration="'.$video->duration_seconds.'"' : '');

        if ($video->isYoutube()) {
            $id = $video->youtube->youtube_id;
            $image = $thumb ? '<img src="'.e($thumb).'" alt="'.$title.'" loading="lazy" decoding="async" referrerpolicy="no-referrer">' : '';
            $player = '<a class="rq-video-play" href="'.e(YouTubeUrl::watchUrl($id)).'" target="_blank" rel="noopener noreferrer">'.$image.'<span class="rq-video-button" aria-label="تشغيل الفيديو"></span></a>';
            $attrs = ' data-source="youtube" data-youtube-id="'.e($id).'"'.($video->youtube->is_short ? ' data-short="1"' : '');
        } else {
            $asset = $video->upload;
            $size = $asset?->width ? ' width="'.$asset->width.'" height="'.$asset->height.'"' : '';
            $poster = $thumb ? ' poster="'.e($thumb).'"' : '';
            $player = '<video controls preload="none" playsinline'.$poster.$size.' src="'.e($this->storage->url($asset)).'"></video>';
            $attrs = ' data-source="upload"';
        }

        $list = '';
        foreach ($chapters as $c) {
            $href = $video->isYoutube() ? YouTubeUrl::watchUrl($video->youtube->youtube_id).'&t='.$c['start'].'s' : '#t='.$c['start'];
            $list .= '<li><a href="'.e($href).'" data-start="'.$c['start'].'"'.($video->isYoutube() ? ' target="_blank" rel="noopener noreferrer"' : '').'>'
                .'<time>'.e($c['time']).'</time> '.e($c['title']).'</a></li>';
        }

        return '<figure class="rq-video"'.$attrs.$common.'>'.$player.'<figcaption>'.$title.'</figcaption>'.($list !== '' ? '<ol class="rq-video-chapters">'.$list.'</ol>' : '').'</figure>';
    }

    /** @return list<array{start: int, time: string, title: string}> */
    public function chapters(Video $video): array
    {
        return DB::table('video_chapters')->where('video_id', $video->id)->orderBy('starts_at_seconds')->get()
            ->map(fn ($c) => ['start' => (int) $c->starts_at_seconds, 'time' => ChapterTime::format((int) $c->starts_at_seconds), 'title' => $c->title])->all();
    }

    /**
     * What the API says about a video (decision 8.13).
     *
     * @return array<string, mixed>
     */
    public function payload(Video $video): array
    {
        $chapters = $this->chapters($video);
        $thumb = $this->thumbnail($video);
        $published = $video->isYoutube() && $video->youtube->published_on_youtube_at ? $video->youtube->published_on_youtube_at : $video->created_at;
        $embed = $video->isYoutube() ? 'https://www.youtube-nocookie.com/embed/'.$video->youtube->youtube_id : null;
        $content = $video->isYoutube() ? null : $this->storage->url($video->upload);

        return [
            'id' => $video->id, 'source' => $video->source, 'path' => '/video/'.$video->id,
            'title' => $video->title(), 'description' => $video->description(),
            'duration_seconds' => $video->duration_seconds, 'thumbnail_url' => $thumb,
            'published_at' => $this->iso($published),
            'youtube' => $video->isYoutube() ? [
                'id' => $video->youtube->youtube_id, 'is_short' => (bool) $video->youtube->is_short,
                'watch_url' => YouTubeUrl::watchUrl($video->youtube->youtube_id), 'embed_url' => $embed,
            ] : null,
            'upload' => $video->isYoutube() ? null : ['url' => $content, 'mime_type' => 'video/mp4', 'width' => $video->upload->width, 'height' => $video->upload->height],
            'chapters' => $chapters,
            'structured_data' => $this->videoObject($video, $chapters, $thumb, $published, $embed, $content),
        ];
    }

    /**
     * Schema.org VideoObject with one Clip per chapter, for Google's video results (8.13). A Clip ends where
     * the next chapter begins; the last one at the end of the video.
     *
     * @param  list<array{start: int, time: string, title: string}>  $chapters
     * @return array<string, mixed>
     */
    private function videoObject(Video $video, array $chapters, ?string $thumb, mixed $published, ?string $embed, ?string $content): array
    {
        $data = [
            '@context' => 'https://schema.org', '@type' => 'VideoObject',
            'name' => $video->title(), 'description' => $video->description() ?: $video->title(),
            'thumbnailUrl' => $thumb ? [$thumb] : [], 'uploadDate' => $this->iso($published),
        ];
        if ($video->duration_seconds) {
            $data['duration'] = self::isoDuration($video->duration_seconds);
        }
        $embed && $data['embedUrl'] = $embed;
        $content && $data['contentUrl'] = $content;

        $parts = [];
        foreach ($chapters as $i => $c) {
            $end = $chapters[$i + 1]['start'] ?? $video->duration_seconds;
            if ($end === null || $end <= $c['start']) {
                continue;   // a Clip needs an end; without the video's length the last one has none
            }
            $parts[] = [
                '@type' => 'Clip', 'name' => $c['title'], 'startOffset' => $c['start'], 'endOffset' => (int) $end,
                'url' => $video->isYoutube() ? YouTubeUrl::watchUrl($video->youtube->youtube_id).'&t='.$c['start'].'s' : $content.'#t='.$c['start'],
            ];
        }
        $parts !== [] && $data['hasPart'] = $parts;

        return $data;
    }

    public static function isoDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h ? $h.'H' : '').($m ? $m.'M' : '').($s || (! $h && ! $m) ? $s.'S' : '');
    }

    private function iso(mixed $time): ?string
    {
        return $time === null ? null : Carbon::parse($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
