import { linkValues, unsubscribe } from "@/lib/server/unsubscribe";
import { visitorOf } from "@/lib/server/visitor";

/*
 * One-click unsubscribe (RFC 8058): a mail program posts `List-Unsubscribe=One-Click` to the newsletter's own link,
 * /newsletter/unsubscribe?s=…&t=… (src/proxy.ts sends that POST here). It comes from the mail program, not from the
 * site's pages, so there is no Origin to check: the link's signature is the proof, checked by the platform.
 */

export async function POST(request: Request) {
  const url = new URL(request.url);
  const values = linkValues(url.searchParams.get("s"), url.searchParams.get("t"));
  const text = (await request.text()).slice(0, 1024);
  const visitor = visitorOf(request.headers);
  if (!values || !visitor || !/(^|&)List-Unsubscribe=One-Click(&|$)/.test(text)) return new Response("Bad request", { status: 400, headers: { "Cache-Control": "no-store" } });

  const result = await unsubscribe(values, visitor);

  return new Response(result.ok ? "Unsubscribed" : "Not unsubscribed", { status: result.ok ? 200 : result.status, headers: { "Cache-Control": "no-store", "Content-Type": "text/plain; charset=utf-8" } });
}
