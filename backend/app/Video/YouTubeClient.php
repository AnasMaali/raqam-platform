<?php

namespace App\Video;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks the YouTube Data API v3 about videos (decisions 8.3, 8.12). The key travels in a request header, not in
 * the address, so no log line, error message or stored text can ever contain it; and every failure is turned
 * into a short Arabic reason before it leaves this class.
 */
class YouTubeClient
{
    public function __construct(private YouTubeQuota $quota) {}

    public function configured(): bool
    {
        return (string) config('raqam.youtube.key') !== '';
    }

    /**
     * @param  list<string>  $ids  at most 50 valid ids
     * @return array<string, YouTubeVideo> the videos found, by id; an id missing from the answer no longer exists
     *
     * @throws YouTubeUnavailable
     */
    public function fetch(array $ids): array
    {
        if (! $this->configured()) {
            throw new YouTubeUnavailable('مفتاح يوتيوب غير مضبوط.');
        }
        if ($ids === [] || count($ids) > 50) {
            throw new \InvalidArgumentException('between 1 and 50 ids');
        }
        if (! $this->quota->allows(1)) {
            throw new YouTubeUnavailable('بلغنا الحد اليومي المسموح لطلبات يوتيوب، حاول غداً أو أدخل البيانات يدوياً.');
        }

        try {
            $response = Http::withHeaders(['X-Goog-Api-Key' => (string) config('raqam.youtube.key')])
                ->acceptJson()->timeout(10)->retry(2, 300, throw: false)
                ->get(rtrim((string) (config('raqam.youtube.api_base') ?: 'https://www.googleapis.com/youtube/v3'), '/').'/videos', ['part' => 'snippet,contentDetails,status', 'id' => implode(',', $ids), 'maxResults' => 50]);
        } catch (ConnectionException) {
            ProviderHealth::failure('youtube', 'تعذّر الاتصال بيوتيوب.');
            throw new YouTubeUnavailable('تعذّر الاتصال بيوتيوب.');
        }

        $this->quota->spend(1);

        if (! $response->successful()) {
            $reason = (string) ($response->json('error.errors.0.reason') ?? '');
            $message = match (true) {
                $reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded' => 'انتهت حصة يوتيوب اليومية.',
                $response->status() === 400 && str_contains($reason, 'keyInvalid') => 'مفتاح يوتيوب غير صحيح.',
                in_array($response->status(), [401, 403], true) => 'يوتيوب رفض الطلب (المفتاح غير مفعّل أو غير مسموح).',
                default => 'ردّ يوتيوب بخطأ ('.$response->status().').',
            };
            ProviderHealth::failure('youtube', $message);
            throw new YouTubeUnavailable($message);
        }

        ProviderHealth::success('youtube');

        $found = [];
        foreach ((array) $response->json('items', []) as $item) {
            $video = $this->read((array) $item);
            if ($video !== null) {
                $found[$video->id] = $video;
            }
        }

        return $found;
    }

    private function read(array $item): ?YouTubeVideo
    {
        $id = $item['id'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            return null;
        }

        $snippet = (array) ($item['snippet'] ?? []);
        $thumbs = (array) ($snippet['thumbnails'] ?? []);
        $thumb = null;
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            $url = $thumbs[$size]['url'] ?? null;
            if (is_string($url) && str_starts_with($url, 'https://')) {
                $thumb = $url;
                break;
            }
        }

        $privacy = (string) ($item['status']['privacyStatus'] ?? 'public');

        return new YouTubeVideo(
            id: $id,
            title: mb_substr(trim((string) ($snippet['title'] ?? '')), 0, 255),
            description: isset($snippet['description']) && $snippet['description'] !== '' ? (string) $snippet['description'] : null,
            thumbnailUrl: $thumb,
            durationSeconds: self::seconds((string) ($item['contentDetails']['duration'] ?? '')),
            publishedAt: isset($snippet['publishedAt']) ? (string) $snippet['publishedAt'] : null,
            availability: $privacy === 'private' ? 'private' : 'ok',
        );
    }

    /** "PT1H2M3S" to seconds; null when it is not a duration (live streams report "P0D"). */
    public static function seconds(string $iso): ?int
    {
        if (! preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m) || $iso === 'P0D' || $iso === 'PT') {
            return null;
        }
        $total = ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600 + ((int) ($m[3] ?? 0)) * 60 + (int) ($m[4] ?? 0);

        return $total > 0 ? $total : null;
    }
}
