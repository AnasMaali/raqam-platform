<?php

use App\Support\ErrorReports;

/*
| Error reports to Sentry (launch preparation): the back end only, off without SENTRY_DSN, and only in Sentry's EU region
| (a DSN elsewhere is ignored, and production refuses to start with one). Errors only: no tracing, profiling, logs or
| metrics. Every report is cleaned by App\Support\ErrorReports (no people, no secrets, no panel address).
*/

return [
    'dsn' => ErrorReports::dsn(env('SENTRY_DSN')),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
    'sample_rate' => 1.0,
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,
    'send_default_pii' => false,
    'before_send' => [ErrorReports::class, 'event'],
    'before_breadcrumb' => [ErrorReports::class, 'breadcrumb'],
    'ignore_transactions' => ['/up', '/livez'],

    // What happened before the error, kept short: which job or command ran. Log lines (a sign-in code in a development
    // mail log), queries, cache keys, Livewire calls, outgoing addresses (a key in the address) and notifications stay out.
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    'crons' => [
        'monitor_scheduled_tasks' => false,
    ],

    'tracing' => [
        'default_integrations' => false,
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
    ],
];
