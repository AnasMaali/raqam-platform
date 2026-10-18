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
        'warn_seconds' => 120,
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

    'site_webhook' => [
        'url' => env('SITE_WEBHOOK_URL'),
        'secret' => env('SITE_WEBHOOK_SECRET'),
    ],

];
