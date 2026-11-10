import { linkValues, unsubscribe } from "@/lib/server/unsubscribe";
import { sameOrigin, visitorOf } from "@/lib/server/visitor";

/*
 * The unsubscribe page's button: the visitor confirmed, the page sends the link's two values here, and this server
 * asks the platform. Only from the site's own pages, JSON only, never cached.
 *
 *   POST /api/newsletter/unsubscribe  { s, t }
 */

const noStore = { "Cache-Control": "no-store, private" };

export async function POST(request: Request) {
  if (!sameOrigin(request)) return answer(403, "site_only", "أرسل الطلب من صفحات الموقع.");
  if (!request.headers.get("content-type")?.startsWith("application/json")) return answer(415, "json_only", "صيغة الطلب غير مقبولة.");
  const visitor = visitorOf(request.headers);
  if (!visitor) return answer(403, "site_only", "تعذّر إلغاء الاشتراك الآن.");
  const text = await request.text();
  if (text.length > 1024) return answer(413, "too_large", "الطلب أكبر من المسموح.");
  let body: { s?: unknown; t?: unknown } | null = null;
  try {
    body = JSON.parse(text);
  } catch {
    return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  }
  const values = linkValues(body?.s, body?.t);
  if (!values) return answer(422, "invalid_link", "رابط إلغاء الاشتراك غير صالح.");

  const result = await unsubscribe(values, visitor);
  if (result.ok) return Response.json({ data: { unsubscribed: true } }, { headers: noStore });

  return answer(result.status, result.status === 422 ? "invalid_link" : "unavailable", result.message);
}

function answer(status: number, code: string, message: string) {
  return Response.json({ error: { code, message } }, { status, headers: noStore });
}
