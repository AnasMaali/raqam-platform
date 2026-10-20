<?php

namespace App\Video;

use App\Media\MediaRejected;
use App\Media\MediaUploader;
use App\Models\MediaAsset;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

/**
 * Adds, edits and orders the videos of the library (decision 8). The rules that touch several tables live here
 * so every screen and every test goes through the same door.
 */
class VideoLibrary
{
    public const MAX_CHAPTERS = 100;

    public function __construct(private YouTubeClient $youtube, private MediaUploader $uploader, private YouTubeThumbnail $thumbnails) {}

    /**
     * Adds a YouTube video from a pasted address (8.2). A video already in the library is returned, not added
     * again (8.5). The facts come from the API (8.3); without a key, or when YouTube cannot be asked, the person
     * types the title (and optionally the length) and the call is made again with `$manual`.
     *
     * @param  array{title?: ?string, duration?: mixed}  $manual
     *
     * @throws VideoRejected
     */
    public function addYoutube(string $input, array $manual = [], ?int $userId = null): Video
    {
        $parsed = YouTubeUrl::parse($input);
        if ($parsed === null) {
            throw new VideoRejected('الرابط ليس رابط فيديو يوتيوب صحيح. الأشكال المقبولة: youtube.com/watch?v=… أو youtu.be/… أو youtube.com/shorts/…');
        }

        $existing = Video::withTrashed()->whereHas('youtube', fn ($q) => $q->where('youtube_id', $parsed['id']))->first();
        if ($existing !== null) {
            $existing->trashed() && $existing->restore();

            return $existing;
        }

        $facts = $this->facts($parsed['id'], $manual);

        $video = DB::transaction(function () use ($parsed, $facts, $userId) {
            $video = (new Video)->forceFill([
                'source' => 'youtube', 'duration_seconds' => $facts['duration'], 'created_by' => $userId,
            ]);
            $video->save();

            DB::table('youtube_videos')->insert([
                'video_id' => $video->id, 'source' => 'youtube', 'youtube_id' => $parsed['id'], 'is_short' => $parsed['is_short'],
                'source_title' => $facts['title'], 'source_description' => $facts['description'],
                'thumbnail_url' => $facts['thumbnail'], 'published_on_youtube_at' => $facts['published_at'],
                'availability' => 'ok', 'last_checked_at' => $facts['from_api'] ? DB::raw('now()') : null, 'fetched_at' => DB::raw('now()'),
            ]);

            return $video->load('youtube');
        });

        $this->fetchThumbnail($video, $userId);

        return $video;
    }

    /** Puts YouTube's picture of the video in our library as its cover, if it has none; a failure only leaves it without. */
    public function fetchThumbnail(Video $video, ?int $userId = null): bool
    {
        if (! config('raqam.youtube.fetch_thumbnails') || $video->poster_media_id !== null || ! $video->isYoutube()) {
            return false;
        }

        $media = $this->thumbnails->store($video->youtube->youtube_id, $video->title(), $userId);
        if ($media === null) {
            return false;
        }
        $video->forceFill(['poster_media_id' => $media])->save();

        return true;
    }

    /** Tries again for the videos that have no picture of ours yet (a failed download, or videos added before this existed). */
    public function fillMissingThumbnails(int $limit = 25): int
    {
        $done = 0;
        Video::query()->with('youtube')->whereNull('poster_media_id')->where('source', 'youtube')
            ->whereHas('youtube', fn ($q) => $q->where('availability', 'ok'))->orderBy('id')->limit($limit)->get()
            ->each(function (Video $video) use (&$done) {
                $done += $this->fetchThumbnail($video) ? 1 : 0;
            });

        return $done;
    }

    /** @return array{title: string, description: ?string, thumbnail: string, duration: ?int, published_at: ?string, from_api: bool} */
    private function facts(string $id, array $manual): array
    {
        $title = trim((string) ($manual['title'] ?? ''));

        if ($this->youtube->configured() && $title === '') {
            try {
                $found = $this->youtube->fetch([$id])[$id] ?? null;
            } catch (YouTubeUnavailable $e) {
                throw new VideoRejected($e->getMessage().' يمكنك إدخال العنوان يدوياً.', 'url', manualEntry: true);
            }

            if ($found === null) {
                throw new VideoRejected('لم يجد يوتيوب هذا الفيديو. تأكد من الرابط وأنه غير محذوف.');
            }
            if ($found->availability !== 'ok') {
                throw new VideoRejected('هذا الفيديو خاص، لا يمكن عرضه في الموقع. اجعله عاماً أو غير مدرج على يوتيوب أولاً.');
            }

            return [
                'title' => $found->title, 'description' => $found->description, 'thumbnail' => $found->thumbnailUrl ?? YouTubeUrl::thumbnail($id),
                'duration' => $found->durationSeconds, 'published_at' => $found->publishedAt, 'from_api' => true,
            ];
        }

        if ($title === '') {
            throw new VideoRejected('مفتاح يوتيوب غير مضبوط، أدخل عنوان الفيديو يدوياً.', 'title', manualEntry: true);
        }

        $duration = ($manual['duration'] ?? '') === '' || ($manual['duration'] ?? null) === null ? null : ChapterTime::parse($manual['duration']);
        if (($manual['duration'] ?? '') !== '' && ($manual['duration'] ?? null) !== null && ($duration === null || $duration < 1)) {
            throw new VideoRejected('مدة الفيديو غير صحيحة، اكتبها مثل 12:30.', 'duration', manualEntry: true);
        }

        return [
            'title' => mb_substr($title, 0, 255), 'description' => null, 'thumbnail' => YouTubeUrl::thumbnail($id),
            'duration' => $duration, 'published_at' => null, 'from_api' => false,
        ];
    }

    /**
     * Adds a short clip uploaded from the computer (8.11). The file goes through the media library (real type,
     * size, ffprobe); the video row then points at it.
     *
     * @throws VideoRejected
     */
    public function addUpload(string $path, string $originalName, string $title, ?string $description, ?int $posterMediaId, ?int $userId): Video
    {
        $title = trim($title);
        if ($title === '') {
            throw new VideoRejected('عنوان الفيديو إجباري.', 'title');
        }

        try {
            $upload = $this->uploader->store($path, $originalName, ['visibility' => 'public'], $userId);
        } catch (MediaRejected $e) {
            throw new VideoRejected($e->getMessage(), 'file');
        }

        $asset = $upload->asset;
        if ($asset->mime_type !== 'video/mp4') {
            throw new VideoRejected('الملف ليس مقطع MP4.', 'file');
        }
        if (Video::withTrashed()->where('upload_media_id', $asset->id)->exists()) {
            throw new VideoRejected('هذا المقطع موجود مسبقاً في مكتبة الفيديو.', 'file');
        }
        $this->assertPoster($posterMediaId);

        $video = (new Video)->forceFill([
            'source' => 'upload', 'display_title' => mb_substr($title, 0, 255), 'display_description' => $this->text($description),
            'duration_seconds' => max(1, (int) ceil((float) $asset->duration_seconds)), 'upload_media_id' => $asset->id,
            'poster_media_id' => $posterMediaId, 'created_by' => $userId,
        ]);
        $video->save();

        return $video;
    }

    /**
     * The title, description and cover the site shows (8.4). YouTube is never written to.
     *
     * @throws VideoRejected
     */
    public function updateDisplay(Video $video, ?string $title, ?string $description, ?int $posterMediaId): void
    {
        $title = $this->text($title);
        if ($video->source === 'upload' && $title === null) {
            throw new VideoRejected('عنوان المقطع إجباري.', 'display_title');
        }
        $this->assertPoster($posterMediaId);

        $video->forceFill([
            'display_title' => $title === null ? null : mb_substr($title, 0, 255),
            'display_description' => $this->text($description), 'poster_media_id' => $posterMediaId,
        ])->save();
    }

    /**
     * Replaces the chapters of a video (8.8). Each time must fall inside the video; times are not repeated.
     *
     * @param  list<array{time?: mixed, title?: mixed}>  $chapters
     *
     * @throws VideoRejected
     */
    public function syncChapters(Video $video, array $chapters): void
    {
        if (count($chapters) > self::MAX_CHAPTERS) {
            throw new VideoRejected('عدد المحاور كبير جداً (الحد '.self::MAX_CHAPTERS.').', 'chapters');
        }

        $rows = [];
        foreach (array_values($chapters) as $i => $chapter) {
            $title = $this->text($chapter['title'] ?? null);
            $at = ChapterTime::parse($chapter['time'] ?? null);
            $n = $i + 1;
            if ($title === null) {
                throw new VideoRejected("المحور رقم {$n}: العنوان إجباري.", 'chapters');
            }
            if ($at === null) {
                throw new VideoRejected("المحور رقم {$n}: الوقت غير صحيح، اكتبه مثل 12:30.", 'chapters');
            }
            if ($video->duration_seconds !== null && $at >= $video->duration_seconds) {
                throw new VideoRejected("المحور رقم {$n}: الوقت ".ChapterTime::format($at).' يتجاوز مدة الفيديو ('.ChapterTime::format($video->duration_seconds).').', 'chapters');
            }
            if (isset($rows[$at])) {
                throw new VideoRejected("المحور رقم {$n}: الوقت ".ChapterTime::format($at).' مكرر.', 'chapters');
            }
            $rows[$at] = ['video_id' => $video->id, 'starts_at_seconds' => $at, 'title' => mb_substr($title, 0, 160)];
        }

        DB::transaction(function () use ($video, $rows) {
            DB::table('video_chapters')->where('video_id', $video->id)->delete();
            ksort($rows);
            $rows !== [] && DB::table('video_chapters')->insert(array_values($rows));
        });
    }

    /** @return list<array{time: string, title: string}> */
    public function chapters(Video $video): array
    {
        return DB::table('video_chapters')->where('video_id', $video->id)->orderBy('starts_at_seconds')->get()
            ->map(fn ($c) => ['time' => ChapterTime::format((int) $c->starts_at_seconds), 'title' => $c->title])->all();
    }

    /**
     * Puts videos in a playlist in the given order (8.9), replacing what was there. Videos that do not exist
     * or sit in the trash are ignored; a repeated video counts once.
     *
     * @param  list<int|string>  $videoIds
     */
    public function setPlaylistVideos(int $playlistId, array $videoIds): void
    {
        $ids = [];
        foreach ($videoIds as $id) {
            if (is_scalar($id) && ctype_digit((string) $id) && strlen((string) $id) <= 18) {
                $ids[(int) $id] = true;
            }
        }
        $valid = Video::query()->whereIn('id', array_keys($ids))->pluck('id')->flip();
        $ordered = array_values(array_filter(array_keys($ids), fn ($id) => $valid->has($id)));

        DB::transaction(function () use ($playlistId, $ordered) {
            // The position is unique per playlist but checked at the end of the transaction, so rows can be rewritten freely.
            DB::table('playlist_videos')->where('playlist_id', $playlistId)->delete();
            $ordered !== [] && DB::table('playlist_videos')->insert(array_map(
                fn ($videoId, $i) => ['playlist_id' => $playlistId, 'video_id' => $videoId, 'sort_order' => $i + 1], $ordered, array_keys($ordered),
            ));
        });
    }

    /** @return list<int> */
    public function playlistVideoIds(int $playlistId): array
    {
        return DB::table('playlist_videos')->where('playlist_id', $playlistId)->orderBy('sort_order')->pluck('video_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @throws VideoRejected */
    private function assertPoster(?int $mediaId): void
    {
        if ($mediaId !== null && ! MediaAsset::query()->publicImages()->whereKey($mediaId)->exists()) {
            throw new VideoRejected('الغلاف يجب أن يكون صورة عامة من مكتبة الوسائط.', 'poster_media_id');
        }
    }

    private function text(?string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text));

        return $text === '' ? null : $text;
    }
}
