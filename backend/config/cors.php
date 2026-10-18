<?php

// The website reads the API from its own server (server-side rendering), so no browser on another site is
// ever told it may read it: no path has CORS headers. If the browser must call the API one day, list the
// origins here on purpose (hardening list in the project notes).

return [
    'paths' => [],
    'allowed_methods' => ['GET'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
