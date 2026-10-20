<?php

namespace App\Video;

/** What the API told us about one video (decision 8.3). */
final class YouTubeVideo
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $thumbnailUrl,
        public readonly ?int $durationSeconds,
        public readonly ?string $publishedAt,
        public readonly string $availability,
    ) {}
}
