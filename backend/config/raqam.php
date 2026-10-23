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
    | Direct ads (decision 13). Views are counted when half an ad is on screen (the site reports them); clicks before the
    | visitor leaves. Counting limits are per visitor (an HMAC of the address, never the address): a view or click
    | repeated within `*_gap` seconds for the same campaign and slot is not counted again, nor beyond `*_per_hour`.
    | AdSense is off in V1 (13.15): with a client id it is offered to the site as a filler, and nothing else changes.
    */
    'ads' => [
        'impression_requests_per_minute' => 60,
        'impressions_per_hour' => 240,
        'impression_gap' => 10,
        'clicks_per_hour' => 20,
        'click_gap' => 30,
        'max_items' => 8,
        'ending_alert_days' => 3,
        'adsense_client' => env('ADSENSE_CLIENT'),
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
        // Fold the sound feminine plural onto the singular (شركات → شركة); on since phase 6. See docs/search.
        // Changing it changes the indexed text: run search:reindex afterwards.
        'feminine_plural' => (bool) env('SEARCH_FEMININE_PLURAL', true),
    ],

    /*
    | Currency board (decision 11, revised 2026-10-12): the Bank of Israel's representative rates, free and without a key.
    | Checked every 30 minutes between these times (Palestine) on Monday to Friday.
    */
    'currency' => [
        'window_from' => '12:00',
        'window_to' => '17:30',
        // Browser tests only: a stand-in for https://boi.org.il. Production refuses to start with it set.
        'boi_base' => env('CURRENCY_BOI_BASE'),
    ],

    /*
    | Weather (decision 12): OpenWeatherMap's free plan, which allows commercial use with the visible credit
    | "Weather data © OpenWeather". The key lives only in .env; empty = nothing is called and the last weather stays.
    | It travels in the address (appid=), so an address to OpenWeather is never logged or stored.
    */
    'weather' => [
        'key' => env('OPENWEATHER_API_KEY'),
        // Browser tests only: a stand-in for https://api.openweathermap.org. Production refuses to start with it set.
        'api_base' => env('OPENWEATHER_API_BASE'),
    ],

    'site_webhook' => [
        'url' => env('SITE_WEBHOOK_URL'),
        'secret' => env('SITE_WEBHOOK_SECRET'),
    ],

    /*
    | System e-mail (decision 20): every message goes out through SMTP from a template the admin edits. Queued messages
    | are tried `attempts` times (20.8), `backoff` seconds apart; the sign-in messages are not queued (a stuck queue must
    | never lock the admin out) and are tried as many times right away, `direct_pause_ms` apart. email_log keeps
    | `retention_days` days (20.9).
    */
    'mail' => [
        'attempts' => 3,
        'backoff' => [30, 120],
        'direct_pause_ms' => 500,
        'retention_days' => 90,
    ],

    /*
    | Admin alerts (20.3, 20.4, 20.10, 20.11): the same alert at most once in `window_minutes`. The disk is low when
    | less than `disk_min_free_percent` of it or less than `disk_min_free_gb` is free.
    */
    'alerts' => [
        'window_minutes' => 60,
        'disk_min_free_percent' => 10,
        'disk_min_free_gb' => 2,
        // The disk the check looks at; the storage folder by default (tests point it elsewhere).
        'disk_path' => env('ALERTS_DISK_PATH'),
    ],

    /*
    | Cloudflare Turnstile (24.10), checked on our server for every public form. The secret lives only in .env; production
    | refuses to start without it, and without it elsewhere the check is skipped (local development). `hostnames` are the
    | site's own hosts, comma separated: a token solved on another site is refused.
    */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'hostnames' => env('TURNSTILE_HOSTNAMES'),
        // Browser tests only: a stand-in for Cloudflare's siteverify. Production refuses to start with it set.
        'verify_url' => env('TURNSTILE_VERIFY_URL'),
    ],

    /*
    | The contact form (20.13): submissions per visitor, and one automatic reply per address in `reply_gap_hours`
    | whoever asks for it, so the form cannot be used to flood somebody's mailbox. At most `alert_emails_per_hour`
    | messages a hour reach the admins by e-mail; beyond that the bell says more are waiting.
    */
    'contact' => [
        'per_hour' => 5,
        'per_day' => 20,
        'requests_per_minute' => 10,
        'reply_gap_hours' => 24,
        'alert_emails_per_hour' => 20,
    ],

    /*
    | Visit statistics (decision 22), internal only: no Google Analytics, no cookie. The site's server reports each page
    | view, interaction and missing page (POST /api/v1/stats/hits); the counters live in Redis (`redis_connection`, the
    | queue connection, which nothing empties) for `redis_seconds`, and stats:flush writes them into the daily tables every
    | hour. Against inflated numbers, per visitor (a salted daily HMAC of the network and browser): the same page within
    | `reload_seconds` counts once; at most `views_per_visitor_day` views a day, `events_per_visitor_hour` interactions an
    | hour (the same one once in `event_gap_seconds`) and `not_found_per_visitor_day` missing pages a day. A visit opens
    | after `visit_minutes` of quiet. `requests_per_minute` is the endpoint's limit per visitor.
    */
    'stats' => [
        'redis_connection' => 'default',
        'redis_seconds' => 172800,
        'reload_seconds' => 10,
        'views_per_visitor_day' => 500,
        'events_per_visitor_hour' => 60,
        'event_gap_seconds' => 10,
        'not_found_per_visitor_day' => 30,
        'visit_minutes' => 30,
        'requests_per_minute' => 120,
    ],

    /*
    | How long visitors' own words are kept (decision 2026-10-24), deleted every night by retention:prune: chatbot
    | conversations from the day they began, contact messages from the day they arrived.
    */
    'retention' => [
        'chat_conversations_days' => 180,
        'contact_messages_days' => 365,
    ],

    /*
    | The public website's address (https://raqam.ps): links in e-mails and the newsletter point there.
    */
    'site_url' => env('SITE_URL'),

    /*
    | Newsletter (decision 19). Confirmation by a 6-digit code (19.2): valid `code_minutes`, `code_attempts` tries,
    | a new one after `resend_seconds`. Whoever asks, one address gets at most `codes_per_email_hour` codes an hour and
    | `codes_per_email_day` a day (decision 2026-10-23); beyond that the answer is the same and nothing is sent.
    | Brevo (19.4–19.6, 19.10): the key lives only in .env; empty = no connection at all. Its webhook proves itself
    | with `webhook_token` in a header (Brevo does not sign), and nothing it says is believed before Brevo's API agrees.
    */
    'newsletter' => [
        'code_minutes' => 30,
        'code_attempts' => 5,
        'resend_seconds' => 60,
        'codes_per_email_hour' => 3,
        'codes_per_email_day' => 6,
        'requests_per_visitor_hour' => 10,
        'confirms_per_visitor_hour' => 30,
        'requests_per_minute' => 10,
        'timebox_ms' => 400,
        'issue_posts' => 8,
        'brevo' => [
            'key' => env('BREVO_API_KEY'),
            'list_id' => env('BREVO_LIST_ID'),
            'webhook_token' => env('BREVO_WEBHOOK_TOKEN'),
            // Browser tests only: a stand-in for https://api.brevo.com/v3. Production refuses to start with it set.
            'api_base' => env('BREVO_API_BASE'),
        ],
    ],

];
