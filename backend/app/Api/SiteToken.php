<?php

namespace App\Api;

use App\Support\ProxyAddresses;
use Illuminate\Http\Request;

/**
 * The website's own server proves itself with X-Raqam-Site-Token (decision after package 5). The token lives in
 * the site's server environment only: no browser ever holds it, because the browser talks to a route of the
 * site on the same domain, and that route calls the API. Empty token = nobody is the site.
 */
final class SiteToken
{
    public const HEADER = 'X-Raqam-Site-Token';

    /** The visitor's address, as the site's server saw it; trusted only together with a valid token. */
    public const VISITOR_HEADER = 'X-Raqam-Visitor-Ip';

    /** The visitor's browser (User-Agent), as the site's server saw it; trusted only together with a valid token. */
    public const AGENT_HEADER = 'X-Raqam-Visitor-Ua';

    public static function valid(Request $request): bool
    {
        $token = (string) config('raqam.api.site_token');

        return $token !== '' && hash_equals($token, (string) $request->header(self::HEADER));
    }

    /**
     * The visitor behind a request of the site, or null when the request is not the site's or names no valid address.
     * The site's server calls the API directly, not through Cloudflare: its header is believed for its token, never for
     * where the request came from (TrustedProxies does not touch it). An IPv4 address written as IPv6 is read as IPv4.
     */
    public static function visitor(Request $request): ?string
    {
        $ip = $request->header(self::VISITOR_HEADER);

        return self::valid($request) && is_string($ip) ? ProxyAddresses::plain($ip) : null;
    }

    /** The address of the person behind a request: the one the site names, or the request's own when it is not the site's. */
    public static function visitorIp(Request $request): ?string
    {
        return self::visitor($request) ?? $request->ip();
    }

    /** The browser of the person behind a request: the one the site names (with its token), or the request's own. */
    public static function visitorAgent(Request $request): ?string
    {
        $agent = self::valid($request) ? $request->header(self::AGENT_HEADER) : $request->userAgent();

        return is_string($agent) ? mb_substr($agent, 0, 512) : null;
    }
}
