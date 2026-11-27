<?php

namespace App\Http\Middleware;

use App\Support\ProxyAddresses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who the visitor is (24.2), in place of the framework's TrustProxies.
 *
 * The chain in production: visitor -> Cloudflare -> Caddy -> PHP. PHP sees Caddy's address; Caddy adds the address it
 * was reached from (a Cloudflare edge) to X-Forwarded-For; Cloudflare adds the visitor's before it, and sets
 * CF-Connecting-IP. Whatever the visitor wrote in those headers sits further left and is never read:
 *
 * - X-Forwarded-For is read from the right, skipping only the trusted machines (ProxyAddresses::trusted()): the first
 *   address that is not one of them is the visitor.
 * - CF-Connecting-IP is believed only when the machine that reached the local proxy is a Cloudflare edge (and
 *   TRUST_CLOUDFLARE is on): Cloudflare overwrites it, so a forged one never gets through; sent from anywhere else it
 *   is dropped, so nothing further in can read it.
 *
 * Only the address and the scheme (X-Forwarded-Proto, for https and HSTS) are taken from the proxies, never the host.
 * An IPv4 address written as IPv6 (::ffff:a.b.c.d) is read as IPv4 everywhere in the chain.
 */
final class TrustedProxies
{
    public const CLOUDFLARE_HEADER = 'CF-Connecting-IP';

    public function handle(Request $request, Closure $next): Response
    {
        $remote = ProxyAddresses::plain($request->server->get('REMOTE_ADDR'));
        if ($remote !== null) {
            $request->server->set('REMOTE_ADDR', $remote);
        }

        // The chain as written, rightmost last, each address in one form (an entry that is not an address stays as it is:
        // it is never trusted, so a reading stops there).
        $chain = array_values(array_filter(array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For'))), fn ($a) => $a !== ''));
        $chain = array_map(fn (string $a) => ProxyAddresses::plain($a) ?? $a, $chain);

        $edge = $this->cloudflareEdge($request, $remote, $chain);
        $visitor = $edge === null ? null : ProxyAddresses::plain($request->headers->get(self::CLOUDFLARE_HEADER));
        $request->headers->remove(self::CLOUDFLARE_HEADER);
        $request->server->remove('HTTP_CF_CONNECTING_IP');
        if ($visitor !== null) {
            // Everything left of the Cloudflare edge is replaced by the address Cloudflare vouches for.
            $chain = [$visitor, ...array_slice($chain, $edge)];
            $request->headers->set(self::CLOUDFLARE_HEADER, $visitor);
            $request->server->set('HTTP_CF_CONNECTING_IP', $visitor);
        }
        if ($chain === []) {
            $request->headers->remove('X-Forwarded-For');
        } else {
            $request->headers->set('X-Forwarded-For', implode(', ', $chain));
        }
        $request->server->set('HTTP_X_FORWARDED_FOR', $request->headers->get('X-Forwarded-For'));

        Request::setTrustedProxies(ProxyAddresses::trusted(), Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        return $next($request);
    }

    /**
     * Where the Cloudflare edge sits in the chain when the request truly came through Cloudflare, so CF-Connecting-IP
     * may be believed: through the local proxy from an edge (its index), or straight from an edge (the chain's end:
     * everything in the chain was then written by Cloudflare or the visitor). Null otherwise.
     *
     * @param  list<string>  $chain
     */
    private function cloudflareEdge(Request $request, ?string $remote, array $chain): ?int
    {
        if (! ProxyAddresses::trustCloudflare() || ProxyAddresses::plain($request->headers->get(self::CLOUDFLARE_HEADER)) === null) {
            return null;
        }
        if (ProxyAddresses::isCloudflare($remote)) {
            return count($chain);
        }
        if (! ProxyAddresses::isLocal($remote)) {
            return null;
        }
        $edge = $this->edgeIndex($chain);

        return $edge !== null && ProxyAddresses::isCloudflare($chain[$edge]) ? $edge : null;
    }

    /**
     * The hop that reached the local proxies: walking the chain from the right, the first address that is not a local
     * proxy.
     *
     * @param  list<string>  $chain
     */
    private function edgeIndex(array $chain): ?int
    {
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (! ProxyAddresses::isLocal($chain[$i])) {
                return $i;
            }
        }

        return null;
    }
}
