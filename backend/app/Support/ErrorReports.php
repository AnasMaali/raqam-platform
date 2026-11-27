<?php

namespace App\Support;

use App\Integrations\Secrets;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * What an error report sent to Sentry may hold (launch preparation). Sentry is off without SENTRY_DSN, and refuses a
 * DSN outside its EU region (data stays in the EU). A report keeps what helps find the fault (the exception, its
 * stack, the route) and loses everything about people and secrets:
 *
 * - no user, no cookies, no headers, no body, no query string, no frame variables;
 * - the panel's address (ADMIN_PATH) written as `/[panel]` wherever it appears;
 * - every secret in the configuration (keys, tokens, passwords, the DSN itself) as `[hidden]`, and key-like query
 *   parameters (Secrets::scrub);
 * - e-mail addresses and IP addresses as `[email]` / `[ip]`.
 */
final class ErrorReports
{
    public const PANEL = '[panel]';

    public const EU_HOST = '.ingest.de.sentry.io';

    /** @var list<string>|null the secrets, read once per report */
    private static ?array $secrets = null;

    /** The DSN to use, or null: none set, or one outside the EU region. */
    public static function dsn(mixed $dsn): ?string
    {
        return is_string($dsn) && $dsn !== '' && self::isEu($dsn) ? $dsn : null;
    }

    public static function isEu(string $dsn): bool
    {
        $host = parse_url($dsn, PHP_URL_HOST);

        return parse_url($dsn, PHP_URL_SCHEME) === 'https' && is_string($host) && str_ends_with(strtolower($host), self::EU_HOST);
    }

    /** Sentry's before_send: the event, cleaned. */
    public static function event(Event $event, ?EventHint $hint = null): ?Event
    {
        self::$secrets = self::secrets();
        try {
            return self::clean($event);
        } finally {
            self::$secrets = null;
        }
    }

    private static function clean(Event $event): Event
    {
        $event->setUser(null);
        $event->setServerName(null);

        $request = $event->getRequest();
        if ($request !== []) {
            $event->setRequest(array_filter([
                'url' => isset($request['url']) ? self::url((string) $request['url']) : null,
                'method' => $request['method'] ?? null,
            ]));
        }

        // Which route failed (its pattern, not the visitor's address): the panel's prefix written as [panel].
        $route = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->route();
        $transaction = $route instanceof Route ? request()->method().' /'.ltrim($route->uri(), '/') : $event->getTransaction();
        $event->setTransaction($transaction === null ? null : self::url($transaction));
        if ($event->getMessage() !== null) {
            $event->setMessage(self::text($event->getMessage()), array_map(fn ($p) => is_string($p) ? self::text($p) : $p, $event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : self::text($event->getMessageFormatted()));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        $event->setBreadcrumb(array_values(array_filter(array_map(fn (Breadcrumb $b) => self::breadcrumb($b), $event->getBreadcrumbs()))));
        $event->setExtra(self::deep($event->getExtra()));
        $event->setTags(self::deep($event->getTags()));
        foreach ($event->getContexts() as $name => $data) {
            $event->setContext($name, self::deep($data));
        }

        return $event;
    }

    /** Sentry's before_breadcrumb: the same cleaning, for a breadcrumb. */
    public static function breadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $clean = $breadcrumb->withMessage(self::text((string) $breadcrumb->getMessage()));
        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $clean = $clean->withMetadata($name, is_string($value) ? self::text($value) : self::deep(is_array($value) ? $value : []));
        }

        return $clean;
    }

    /** An address without its query string or fragment (a signed link's signature, a token), in clean text. */
    public static function url(string $url): string
    {
        return self::text((string) preg_replace('~[?#].*$~s', '', $url));
    }

    public static function text(string $text): string
    {
        foreach (self::$secrets ?? self::secrets() as $secret) {
            $text = str_replace([$secret, rawurlencode($secret)], '[hidden]', $text);
        }
        $panel = trim((string) config('raqam.admin_path'), '/');
        if ($panel !== '') {
            $text = (string) preg_replace('~(?<![A-Za-z0-9_-])'.preg_quote($panel, '~').'(?![A-Za-z0-9_-])~', self::PANEL, $text);
        }
        $text = Secrets::scrub($text);
        $text = (string) preg_replace('~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~', '[email]', $text);
        $text = (string) preg_replace('~\b(?:\d{1,3}\.){3}\d{1,3}\b~', '[ip]', $text);

        // IPv6: a run with "::" in it, or eight full groups (a clock's 12:30:45 is neither).
        return (string) preg_replace('~(?<![A-Za-z0-9:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:)*[0-9a-f]{0,4}::(?:[0-9a-f]{1,4}:)*[0-9a-f]{0,4})(?![A-Za-z0-9:])~i', '[ip]', $text);
    }

    /**
     * Every secret in the configuration: a string of 8 characters or more under a name that says key, secret, token,
     * password or dsn.
     *
     * @return list<string>
     */
    public static function secrets(): array
    {
        $secrets = [];
        foreach (Arr::dot((array) config()->all()) as $name => $value) {
            if (is_string($value) && strlen($value) >= 8 && preg_match('~(key|secret|token|password|dsn)(s?\.\d+)?$~i', (string) $name)) {
                $secrets[] = $value;
            }
        }
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return array_values(array_unique($secrets));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function deep(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = self::text($value);
            }
        });

        return $data;
    }
}
