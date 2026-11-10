import { apiPost } from "@/lib/server/api";
import { sameOrigin, visitorOf } from "@/lib/server/visitor";

/*
 * The contact form (20.13), through the site's server: the platform's POST /contact takes only the site token and the
 * visitor's address, which the browser never holds. Only from the site's own pages (Origin), JSON only, 16 KB at most.
 * The platform checks Turnstile, the honeypot `website`, the fields and its limits, and answers in Arabic.
 *
 *   POST /api/contact  { name, email, phone, subject, message, website, turnstile_token }
 */

const maxBodyBytes = 16_384;
const noStore = { "Cache-Control": "no-store, private" };
const fields = ["name", "email", "phone", "subject", "message", "website", "turnstile_token"] as const;
const unavailable = "تعذّر إرسال الرسالة الآن. حاول بعد قليل أو تواصل معنا بالهاتف.";

export async function POST(request: Request) {
  if (!sameOrigin(request)) return answer(403, "site_only", "أرسل النموذج من صفحات الموقع.");
  if (!request.headers.get("content-type")?.startsWith("application/json")) return answer(415, "json_only", "صيغة الطلب غير مقبولة.");
  const visitor = visitorOf(request.headers);
  if (!visitor) return answer(403, "site_only", unavailable);

  const text = await request.text();
  if (Buffer.byteLength(text) > maxBodyBytes) return answer(413, "too_large", "الرسالة أطول من المسموح.");
  let body: unknown;
  try {
    body = JSON.parse(text || "{}");
  } catch {
    return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  }
  if (typeof body !== "object" || body === null || Array.isArray(body)) return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  // Only the form's own fields go on, each as text.
  const sent = Object.fromEntries(fields.map((key) => [key, typeof (body as Record<string, unknown>)[key] === "string" ? (body as Record<string, string>)[key] : ""]));
  if (!sent.phone) sent.phone = null as unknown as string;

  let result: { status: number; body: unknown };
  try {
    result = await apiPost("/contact", sent, visitor);
  } catch (error) {
    console.error(`[site] contact form: ${(error as Error).message}`);

    return answer(502, "unavailable", unavailable);
  }
  if (result.status === 202) return Response.json({ data: { received: true } }, { status: 202, headers: noStore });

  const error = (result.body as { error?: { code?: string; message?: string; details?: unknown } } | null)?.error;
  const status = result.status >= 400 && result.status < 500 ? result.status : 502;

  return Response.json({ error: { code: error?.code ?? "unavailable", message: error?.message ?? unavailable, details: error?.details } }, { status, headers: noStore });
}

function answer(status: number, code: string, message: string) {
  return Response.json({ error: { code, message } }, { status, headers: noStore });
}
