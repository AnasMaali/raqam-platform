import "server-only";

import { isIP } from "node:net";

import type { Visitor } from "./api";

/*
 * The visitor's address, from the headers the site's own proxies set. Behind Cloudflare (TRUST_CLOUDFLARE=1) it is
 * CF-Connecting-IP; otherwise the last address in X-Forwarded-For, the one the site's own reverse proxy added (any
 * address before it was written by the visitor and proves nothing). Without one the form is refused, never counted
 * under a shared address.
 */
export function visitorOf(headers: Headers): Visitor | null {
  const cloudflare = process.env.TRUST_CLOUDFLARE === "1" ? headers.get("cf-connecting-ip")?.trim() : undefined;
  const forwarded = headers.get("x-forwarded-for")?.split(",").at(-1)?.trim();
  const ip = cloudflare || forwarded || "";
  if (!isIP(ip)) return null;

  return { ip, userAgent: headers.get("user-agent") ?? "" };
}

/** A form may be sent only from the site's own pages: the browser's Origin must be this site's. */
export function sameOrigin(request: Request): boolean {
  const origin = request.headers.get("origin");
  if (!origin) return false;
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  try {
    return new URL(origin).host === host;
  } catch {
    return false;
  }
}
