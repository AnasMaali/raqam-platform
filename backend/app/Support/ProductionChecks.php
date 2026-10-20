<?php

namespace App\Support;

use RuntimeException;

/**
 * What must be true before the application serves anything in production. A failed check stops the
 * boot: a debug page, a mail "log" that swallows sign-in codes, a session cookie sent over plain HTTP
 * or a missing secret are not things to discover later. Messages name the setting, never its value.
 */
final class ProductionChecks
{
    /** Console commands that run while the image is built, before any secret exists. */
    private const BUILD_COMMANDS = [
        'package:discover', 'config:cache', 'config:clear', 'route:cache', 'view:cache', 'event:cache',
        'optimize', 'optimize:clear', 'key:generate', 'storage:link', 'filament:assets', 'about', 'list', 'help', 'env',
    ];

    /** @return list<string> */
    public static function failures(): array
    {
        $failures = [];

        if (config('app.debug')) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $failures[] = 'MAIL_MAILER must deliver real mail: "'.config('mail.default').'" would swallow the sign-in codes.';
        }

        if (config('session.secure') !== true) {
            $failures[] = 'The session cookie must be Secure (SESSION_SECURE_COOKIE=true).';
        }
        if (config('session.http_only') !== true) {
            $failures[] = 'The session cookie must be HttpOnly (SESSION_HTTP_ONLY=true).';
        }
        if (config('session.same_site') !== 'strict') {
            $failures[] = 'The session cookie must be SameSite=Strict (SESSION_SAME_SITE=strict).';
        }
        if (! str_starts_with((string) config('session.cookie'), '__Host-') || config('session.path') !== '/' || config('session.domain')) {
            $failures[] = 'The session cookie must use the __Host- prefix, path "/" and no domain.';
        }
        if ((int) config('session.lifetime') > (int) config('raqam.auth.idle_minutes')) {
            $failures[] = 'SESSION_LIFETIME must not exceed the '.config('raqam.auth.idle_minutes').'-minute idle limit.';
        }

        if (filled(config('raqam.auth.idle_seconds')) || (int) config('raqam.auth.warn_seconds') !== 120) {
            $failures[] = 'ADMIN_IDLE_SECONDS and ADMIN_WARN_SECONDS are for browser tests only and must not be set.';
        }

        if (filled(config('raqam.youtube.api_base')) || filled(config('raqam.youtube.image_base'))) {
            $failures[] = 'YOUTUBE_API_BASE and YOUTUBE_IMAGE_BASE are for browser tests only and must not be set.';
        }

        foreach ([
            'APP_KEY' => 'app.key',
            'ADMIN_CODE_KEY' => 'raqam.auth.code_key',
            'TRACKING_HASH_KEY' => 'raqam.tracking_key',
            'ADMIN_PATH' => 'raqam.admin_path',
        ] as $name => $setting) {
            if (blank(config($setting))) {
                $failures[] = "{$name} is not set.";
            }
        }

        return $failures;
    }

    public static function enforce(): void
    {
        if ($failures = self::failures()) {
            throw new RuntimeException("Refusing to start in production:\n - ".implode("\n - ", $failures));
        }
    }

    /** Web requests, queue workers and the scheduler are checked; build-time console commands are not. */
    public static function appliesTo(bool $console, ?string $command): bool
    {
        return ! $console || ! in_array($command, self::BUILD_COMMANDS, true);
    }
}
