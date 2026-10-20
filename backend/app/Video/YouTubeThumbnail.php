<?php

namespace App\Video;

use App\Media\MediaRejected;
use App\Media\MediaUploader;
use Illuminate\Support\Facades\Http;

/**
 * Copies a YouTube video's picture into our media library when the video is added (decision 8.6), so the site
 * shows our own copy and a visitor's browser never contacts Google before they press play.
 *
 * The address is built from the video id here, never taken from the API's answer or a person's input, and the
 * answer must be a small JPEG; whatever arrives then goes through the media library like any upload.
 */
class YouTubeThumbnail
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private MediaUploader $uploader) {}

    /** @return int|null the media id of the stored picture, or null when YouTube had none or could not be reached */
    public function store(string $youtubeId, string $title, ?int $userId): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId) !== 1) {
            return null;
        }

        foreach (['maxresdefault', 'hqdefault'] as $name) {
            $bytes = $this->download(rtrim((string) (config('raqam.youtube.image_base') ?: 'https://i.ytimg.com/vi'), '/')."/{$youtubeId}/{$name}.jpg");
            if ($bytes === null) {
                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'raqam-thumb-');
            try {
                file_put_contents($path, $bytes);

                return $this->uploader->store($path, "youtube-{$youtubeId}.jpg", [
                    'alt_text' => mb_substr($title, 0, 250) ?: 'صورة الفيديو', 'credit' => 'YouTube', 'visibility' => 'public',
                ], $userId)->asset->id;
            } catch (MediaRejected) {
                continue;
            } finally {
                @unlink($path);
            }
        }

        return null;
    }

    private function download(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->accept('image/jpeg')->get($url);
        } catch (\Throwable) {
            return null;
        }

        $body = $response->body();
        $type = strtolower((string) $response->header('Content-Type'));

        return $response->successful() && $body !== '' && strlen($body) <= self::MAX_BYTES && str_starts_with($type, 'image/jpeg') ? $body : null;
    }
}
