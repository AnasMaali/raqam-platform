<?php

return [

    /*
    | URL path of the admin panel. Required: it comes from the environment and has no default,
    | so the real path never appears in the repository.
    */
    'admin_path' => env('ADMIN_PATH'),

    /*
    | Meilisearch. An empty key switches indexing off (tests, CI, a machine without the engine).
    */
    'search' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'posts_index' => env('MEILISEARCH_POSTS_INDEX', 'posts'),
    ],

];
