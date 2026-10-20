<?php

return [

    /*
    | Where new uploads go: "local" (this server) or "r2" (Cloudflare R2). Decision 9.10: switching is a
    | configuration change only. Files already stored stay where they are (each asset records its disk).
    */
    'disk' => env('MEDIA_DISK', 'local'),

    /*
    | The Laravel disk behind each (disk, visibility) pair; see config/filesystems.php.
    */
    'disks' => [
        'local' => ['public' => 'media_local_public', 'private' => 'media_local_private'],
        'r2' => ['public' => 'media_r2_public', 'private' => 'media_r2_private'],
    ],

    /*
    | Upload limits in bytes (decision 9.9) and protection against decompression bombs (9.8).
    | Images are re-encoded and shrunk on the way in: what is stored never exceeds `image_stored`.
    */
    'limits' => [
        'image_upload' => 25 * 1024 * 1024,
        'image_stored' => 10 * 1024 * 1024,
        'svg' => 2 * 1024 * 1024,
        'pdf' => 20 * 1024 * 1024,
        // Short uploaded clips (decision 8.11) and Rive animation files (7.10).
        'video' => 30 * 1024 * 1024,
        'video_seconds' => 90,
        'rive' => 5 * 1024 * 1024,
        'max_pixels' => 24_000_000,
        'max_side' => 6000,
    ],

    /*
    | Renditions (9.3, 9.4). A width bigger than the original is skipped; thumb and og are always made.
    | thumb is a square crop and og is 1200x630, both around the focal point (9.5).
    */
    'variants' => [
        'thumb' => ['width' => 320, 'height' => 320],
        'sm' => ['width' => 480],
        'md' => ['width' => 768],
        'lg' => ['width' => 1200],
        'xl' => ['width' => 1920],
        'og' => ['width' => 1200, 'height' => 630],
    ],

    /*
    | The ffprobe program that measures uploaded videos (decision 8.11). Normally just the name on PATH.
    */
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

    'formats' => ['avif', 'webp', 'jpeg'],

    'quality' => ['avif' => 50, 'webp' => 80, 'jpeg' => 82, 'master' => 90],

    /*
    | How long a link to a private file stays valid, in minutes.
    */
    'private_url_minutes' => 10,

];
