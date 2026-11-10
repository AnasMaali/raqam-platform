import { cleanQuery } from "@/lib/search";
import { runSearch } from "@/lib/server/search";
import { visitorOf } from "@/lib/server/visitor";

/*
 * The instant results (10.1): the search page asks this route 250 ms after the visitor stops typing, and this server
 * asks the platform with the site token and the visitor's address. Only for the site's own pages; never cached, never
 * indexed; 60 requests a minute per visitor here too, so the route cannot be used to search the platform for others.
 * Never `track`: what is typed on the way is not a search the visitor settled on.
 *
 *   GET /api/search?q=…&category=…&section=…&kind=…&from=…&to=…&sort=…&page=…
 */

const perMinute = 60;
const seen = new Map<string, { count: number; since: number }>();
const headers = { "Cache-Control": "no-store, private", "X-Robots-Tag": "noindex, nofollow" };

/** A fixed window per visitor's address; the oldest entries go first past 10 000 visitors. */
function allowed(ip: string): boolean {
  const now = Date.now();
  const entry = seen.get(ip);
  if (!entry || now - entry.since >= 60_000) {
    seen.delete(ip);
    while (seen.size >= 10_000) seen.delete(seen.keys().next().value!);
    seen.set(ip, { count: 1, since: now });
    return true;
  }
  entry.count += 1;

  return entry.count <= perMinute;
}

/** The browser says where a request comes from (Sec-Fetch-Site); a request from another site is refused. */
function fromThisSite(request: Request): boolean {
  const site = request.headers.get("sec-fetch-site");
  if (site) return site === "same-origin";
  const referer = request.headers.get("referer");
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  try {
    return !!referer && new URL(referer).host === host;
  } catch {
    return false;
  }
}

export async function GET(request: Request) {
  if (!fromThisSite(request)) return Response.json({ error: { code: "site_only", message: "من صفحات الموقع فقط." } }, { status: 403, headers });
  const visitor = visitorOf(request.headers);
  if (!visitor) return Response.json({ error: { code: "site_only", message: "تعذّر البحث الآن." } }, { status: 403, headers });
  if (!allowed(visitor.ip)) return Response.json({ error: { code: "too_many", message: "بحثت كثيراً خلال دقيقة. انتظر قليلاً ثم أعد المحاولة." } }, { status: 429, headers: { ...headers, "Retry-After": "60" } });

  const query = cleanQuery(Object.fromEntries(new URL(request.url).searchParams));
  if (!query.q || query.q.length < 2) return Response.json({ error: { code: "short", message: "اكتب حرفين على الأقل." } }, { status: 422, headers });

  const result = await runSearch(query, visitor, false);
  if (result.ok) return Response.json(result.answer, { headers });

  return Response.json({ error: { code: result.status === 429 ? "too_many" : "unavailable", message: result.message } }, { status: result.status, headers });
}
