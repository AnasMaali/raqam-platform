import { cookies } from "next/headers";

import { apiPost, type Visitor } from "@/lib/server/api";
import { sameOrigin, visitorOf } from "@/lib/server/visitor";

/*
 * The newsletter form's three steps, through the site's server: the platform's newsletter routes take only the site
 * token and the visitor's address, which the browser never holds. The challenge the platform answers with stays in an
 * httpOnly cookie of this route; the browser only learns when it may ask for a new code.
 *
 *   POST /api/newsletter/subscribe  { email, website, placement, path, turnstile_token }
 *   POST /api/newsletter/resend     {}
 *   POST /api/newsletter/confirm    { code, website }
 */

const cookieName = "raqam_newsletter";
const cookiePath = "/api/newsletter";
const placements = new Set(["home", "footer", "post_end", "newsletter_page"]);
const maxBodyBytes = 4096;
const noStore = { "Cache-Control": "no-store, private" };

const unavailable = "تعذّر الاشتراك الآن. حاول بعد قليل.";
const expired = "انتهت صلاحية الرمز. اطلب رمزاً جديداً.";

type Step = "subscribe" | "resend" | "confirm";
type Sent = { status: number; body: unknown };

export async function POST(request: Request, { params }: RouteContext<"/api/newsletter/[step]">) {
  const { step } = await params;
  if (step !== "subscribe" && step !== "resend" && step !== "confirm") return answer(404, "not_found", "غير موجود.");
  if (!sameOrigin(request)) return answer(403, "site_only", "أرسل النموذج من صفحات الموقع.");
  if (!request.headers.get("content-type")?.startsWith("application/json")) return answer(415, "json_only", "صيغة الطلب غير مقبولة.");

  const visitor = visitorOf(request.headers);
  if (!visitor) return answer(403, "site_only", unavailable);

  const text = await request.text();
  if (Buffer.byteLength(text) > maxBodyBytes) return answer(413, "too_large", "الطلب أكبر من المسموح.");
  let body: unknown;
  try {
    body = JSON.parse(text || "{}");
  } catch {
    return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  }
  if (typeof body !== "object" || body === null || Array.isArray(body)) return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  const field = (key: string) => {
    const value = (body as Record<string, unknown>)[key];

    return typeof value === "string" ? value : "";
  };

  const jar = await cookies();
  const challenge = jar.get(cookieName)?.value;
  if (step !== "subscribe" && !challenge) return answer(422, "expired", expired);

  let sent: Sent;
  try {
    sent = await send(step, field, challenge ?? "", visitor);
  } catch (error) {
    console.error(`[site] newsletter ${step}: ${(error as Error).message}`);

    return answer(502, "unavailable", unavailable);
  }

  const data = (sent.body as { data?: Record<string, unknown> } | null)?.data;
  if (sent.status === 202 && typeof data?.challenge === "string") {
    jar.set(cookieName, data.challenge, {
      httpOnly: true,
      secure: (process.env.SITE_URL ?? "").startsWith("https://"),
      sameSite: "strict",
      path: cookiePath,
      maxAge: Number(data.expires_in) || 1800,
    });

    return Response.json({ data: { resend_after: data.resend_after, expires_in: data.expires_in } }, { status: 202, headers: noStore });
  }
  if (sent.status === 200 && step === "confirm") {
    jar.delete({ name: cookieName, path: cookiePath });

    return Response.json({ data: { confirmed: true } }, { headers: noStore });
  }

  // The platform's own message (in Arabic), or a general one when it gave none.
  const error = (sent.body as { error?: { code?: string; message?: string; details?: unknown } } | null)?.error;
  if (error?.code === "expired") jar.delete({ name: cookieName, path: cookiePath });
  const status = sent.status >= 400 && sent.status < 500 ? sent.status : 502;

  return Response.json(
    { error: { code: error?.code ?? "unavailable", message: error?.message ?? unavailable, details: error?.details } },
    { status, headers: noStore },
  );
}

function send(step: Step, field: (key: string) => string, challenge: string, visitor: Visitor): Promise<Sent> {
  if (step === "resend") return apiPost("/newsletter/resend", { challenge }, visitor);
  if (step === "confirm") return apiPost("/newsletter/confirm", { challenge, code: field("code"), website: field("website") }, visitor);

  const path = field("path");

  return apiPost(
    "/newsletter/subscribe",
    {
      email: field("email"),
      website: field("website"),
      placement: placements.has(field("placement")) ? field("placement") : "home",
      path: path.startsWith("/") && !path.startsWith("//") ? path.slice(0, 500) : null,
      turnstile_token: field("turnstile_token"),
    },
    visitor,
  );
}

function answer(status: number, code: string, message: string) {
  return Response.json({ error: { code, message } }, { status, headers: noStore });
}
