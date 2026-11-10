import "server-only";

import { sameOrigin } from "./visitor";

/*
 * What the page's reports (components/site/ViewReporter) share on the way in: only from the site's own pages, a small
 * body, JSON in a text/plain beacon. The answer is always 204 and never says whether anything counted.
 */

export const received = () => new Response(null, { status: 204, headers: { "Cache-Control": "no-store, private" } });

export async function beaconBody(request: Request, maxBytes = 4096): Promise<Record<string, unknown> | null> {
  if (!sameOrigin(request)) return null;
  const text = await request.text();
  if (!text || Buffer.byteLength(text) > maxBytes) return null;
  try {
    const body = JSON.parse(text);
    return body && typeof body === "object" && !Array.isArray(body) ? body : null;
  } catch {
    return null;
  }
}
