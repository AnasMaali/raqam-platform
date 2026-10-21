<?php

return [

    /*
    | URL path of the admin panel. Required: it comes from the environment and has no default,
    | so the real path never appears in the repository.
    */
    'admin_path' => env('ADMIN_PATH'),

    /*
    | Admin sign-in (decisions 2.4, 2.10, 24.9).
    | code_key is the secret the 6-digit e-mail codes are HMAC-ed with. It has no default and is
    | not APP_KEY, so a leaked database cannot be used to guess a code offline.
    */
    'auth' => [
        'code_key' => env('ADMIN_CODE_KEY'),
        'code_minutes' => 10,
        'code_attempts' => 5,
        'resend_seconds' => 60,
        // Signed in: out after 30 minutes without activity, and after 12 hours whatever happens.
        'idle_minutes' => 30,
        'max_hours' => 12,
        'warn_seconds' => (int) env('ADMIN_WARN_SECONDS', 120),
        // Browser tests only: a shorter idle limit in seconds. Production refuses to start with it set.
        'idle_seconds' => env('ADMIN_IDLE_SECONDS'),
        'sends_per_hour' => 3,
        // Password submissions (each one counts, right or wrong): per e-mail + IP, per e-mail, per IP.
        'attempts_per_minute' => 5,
        'attempts_per_email_hour' => 20,
        'attempts_per_ip_hour' => 30,
    ],

    /*
    | Secret for the HMAC of IP addresses and device cookies (login_attempts, rate-limit keys, audit log).
    | Separate from APP_KEY, no default: a raw IP address is never stored.
    */
    'tracking_key' => env('TRACKING_HASH_KEY'),

    /*
    | The website is told when content changes (decision 21.16). Both must be set, or nothing is sent.
    | The secret signs every request; the site checks it.
    */
    /*
    | The website sends this secret in the X-Raqam-Site-Token header and gets a much higher API limit than
    | anonymous clients. Empty means nobody gets it. Compared with hash_equals.
    */
    'api' => [
        'site_token' => env('API_SITE_TOKEN'),
        'limit' => 120,
        'site_limit' => 3000,
    ],

    /*
    | YouTube Data API v3 (decisions 8.3, 8.14). The key lives only in .env; without it videos are added by hand.
    | The free quota is 10,000 units a day and resets at midnight Pacific time; one videos.list call costs 1 unit.
    | We stop calling at `quota_budget` and warn at `quota_warn`.
    */
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
        'quota_budget' => 9000,
        'quota_warn' => 7000,
        // Browser tests only: stand-in servers for the API and the pictures. Production refuses to start with them set.
        'api_base' => env('YOUTUBE_API_BASE'),
        'image_base' => env('YOUTUBE_IMAGE_BASE'),
        // Copy each video's picture into the media library when it is added (decision 8.6).
        'fetch_thumbnails' => (bool) env('YOUTUBE_FETCH_THUMBNAILS', true),
    ],

    /*
    | The page colour a character's accent is checked against (decision 7.14): it must reach 4.5 to 1.
    */
    'awareness' => [
        'background' => '#FFFFFF',
    ],

    /*
    | Meilisearch v1.54.3 (decision 10). The key is a scoped API key that reaches only the indexes starting with
    | the prefix (search:create-key makes it); the master key never sits in the application. An empty key switches
    | the engine off: nothing is indexed and every search runs on PostgreSQL (tests, CI, a machine without it).
    | When a call fails the engine counts as down for `down_seconds`, so visitors do not each wait for a timeout.
    */
    'search' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'prefix' => env('MEILISEARCH_PREFIX', 'raqam_'),
        'timeout' => 1.0,
        'index_timeout' => 10,
        'down_seconds' => 30,
        // Requests per minute per visitor (10.13).
        'per_minute' => 60,
        // New words the search statistics take in a day (Palestine time); known words are always counted.
        'new_terms_per_day' => 2000,
        // Experiment (phase 5): fold the sound feminine plural onto the singular (شركات → شركة). See docs/search.
        'feminine_plural' => (bool) env('SEARCH_FEMININE_PLURAL', false),
    ],

    /*
    | Currency board (decision 11, revised 2026-10-12): the Bank of Israel's representative rates, free and without a key.
    | Checked every 30 minutes between these times (Palestine) on Monday to Friday.
    */
    'currency' => [
        'window_from' => '12:00',
        'window_to' => '16:00',
        // Browser tests only: a stand-in for https://boi.org.il. Production refuses to start with it set.
        'boi_base' => env('CURRENCY_BOI_BASE'),
    ],

    'site_webhook' => [
        'url' => env('SITE_WEBHOOK_URL'),
        'secret' => env('SITE_WEBHOOK_SECRET'),
    ],

];
