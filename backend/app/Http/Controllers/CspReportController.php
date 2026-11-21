<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * What the panel's content security policy blocked (App\Http\PanelContentPolicy), as the browser reports it: one line
 * of the log for each report, the same as the website's (`[csp] <directive> blocked <address> on <page>`), with both
 * addresses without their query or fragment (a search or a token can sit there) and the panel's address written as
 * <panel>, since a log may be read by more people than the panel's address is given to. Never the visitor's address or
 * browser. Only the two kinds of CSP report, at most 8 KB, 60 a minute from one address; outside the web group, so no
 * session and no cookie.
 *
 *   POST /csp-report  application/csp-report (report-uri) or application/reports+json (report-to)
 */
class CspReportController
{
    private const MAX_BYTES = 8192;

    public function __invoke(Request $request): Response
    {
        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        if ($type !== 'application/csp-report' && $type !== 'application/reports+json') {
            return self::answer(415);
        }
        $text = $request->getContent();
        if (strlen($text) > self::MAX_BYTES) {
            return self::answer(413);
        }
        $body = json_decode($text, true);
        if (! is_array($body)) {
            return self::answer(400);
        }

        $reports = $type === 'application/csp-report'
            ? [self::fromReportUri($body)]
            : (array_is_list($body) ? array_map(self::fromReportTo(...), $body) : []);
        $violations = array_values(array_filter($reports));
        if ($violations === []) {
            return self::answer(400);
        }
        foreach ($violations as [$directive, $blocked, $page]) {
            Log::warning("[csp] {$directive} blocked {$blocked} on {$page}");
        }

        return self::answer(204);
    }

    /** report-uri: { "csp-report": { "effective-directive", "blocked-uri", "document-uri" } }. */
    private static function fromReportUri(array $body): ?array
    {
        $report = $body['csp-report'] ?? null;

        return is_array($report)
            ? self::violation($report['effective-directive'] ?? $report['violated-directive'] ?? null, $report['blocked-uri'] ?? null, $report['document-uri'] ?? null)
            : null;
    }

    /** report-to: [{ "type": "csp-violation", "body": { "effectiveDirective", "blockedURL", "documentURL" } }, …]. */
    private static function fromReportTo(mixed $entry): ?array
    {
        if (! is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation' || ! is_array($entry['body'] ?? null)) {
            return null;
        }

        return self::violation($entry['body']['effectiveDirective'] ?? null, $entry['body']['blockedURL'] ?? null, $entry['body']['documentURL'] ?? null);
    }

    private static function violation(mixed $directive, mixed $blocked, mixed $page): ?array
    {
        if (! is_string($directive) || ! preg_match('/^[a-z-]{1,40}$/', $directive)) {
            return null;
        }
        $pageAddress = self::address($page);
        if ($pageAddress === null || ! str_starts_with($pageAddress, 'http')) {
            return null;
        }

        return [$directive, self::address($blocked) ?? 'unknown', self::hidePanel($pageAddress)];
    }

    /** An http(s) address as origin and path (at most 300 characters), or the browser's word for the rest (inline, eval…). */
    private static function address(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (preg_match('/^[a-z-]{1,20}$/', $value)) {
            return $value;
        }
        $url = parse_url($value);
        if (! is_array($url) || ! isset($url['scheme'])) {
            return null;
        }
        $scheme = strtolower($url['scheme']);
        if (in_array($scheme, ['http', 'https'], true) && isset($url['host'])) {
            $origin = "{$scheme}://".strtolower($url['host']).(isset($url['port']) ? ':'.$url['port'] : '');

            return mb_substr($origin.($url['path'] ?? '/'), 0, 300);
        }

        return preg_match('/^[a-z][a-z0-9+.-]{0,19}$/', $scheme) ? $scheme : null;
    }

    private static function hidePanel(string $address): string
    {
        $panel = trim((string) config('raqam.admin_path'), '/');

        return $panel === '' ? $address : (string) preg_replace('#^(https?://[^/]+)/'.preg_quote($panel, '#').'(?=/|$)#', '$1/<panel>', $address);
    }

    private static function answer(int $status): Response
    {
        return response('', $status, ['Cache-Control' => 'no-store']);
    }
}
