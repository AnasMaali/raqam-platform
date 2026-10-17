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
        'sends_per_hour' => 3,
    ],

];
