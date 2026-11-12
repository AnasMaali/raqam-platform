import { readConfig, readReply, sitePath } from "@/lib/chat";
import { apiGet, apiPost } from "@/lib/server/api";
import { serverEnv } from "@/lib/server/env";
import { sameOrigin, visitorOf } from "@/lib/server/visitor";

/*
 * The chatbot (18), through the site's server: the platform's POST /chat/messages takes the site token and the
 * visitor's address, which the browser never holds. Only for the site's own pages (a request from another Origin gets
 * 403), JSON only, 4 KB at most, never cached. The answer goes back as text and site links only (src/lib/chat.ts).
 *
 *   GET  /api/chat   the welcome and the first buttons, read when the visitor opens the chat (18.15)
 *   POST /api/chat   { message | button, conversation, path, website }
 */

const maxBodyBytes = 4096;
const noStore = { "Cache-Control": "no-store, private", "X-Robots-Tag": "noindex, nofollow" };
const unavailable = "تعذّر الوصول إلى المساعد الآن. حاول بعد قليل.";

/** A request another site's page made: an Origin that is not this site's, or the browser saying it is cross-site. */
function fromElsewhere(request: Request): boolean {
  if (request.headers.get("origin") !== null && !sameOrigin(request)) return true;
  const site = request.headers.get("sec-fetch-site");

  return site !== null && site !== "same-origin" && site !== "none";
}

export async function GET(request: Request) {
  if (fromElsewhere(request)) return answer(403, "site_only", "المساعد متاح من صفحات الموقع فقط.");
  try {
    const body = await apiGet<{ data: unknown }>("/chat", { cache: "no-store" });

    return Response.json({ data: readConfig(body.data) }, { headers: noStore });
  } catch (error) {
    console.error(`[site] chat settings: ${(error as Error).message}`);

    return answer(502, "unavailable", unavailable);
  }
}

export async function POST(request: Request) {
  if (!sameOrigin(request) || fromElsewhere(request)) return answer(403, "site_only", "المساعد متاح من صفحات الموقع فقط.");
  if (!request.headers.get("content-type")?.startsWith("application/json")) return answer(415, "json_only", "صيغة الطلب غير مقبولة.");
  const visitor = visitorOf(request.headers);
  if (!visitor) return answer(403, "site_only", unavailable);

  const text = await request.text();
  if (Buffer.byteLength(text) > maxBodyBytes) return answer(413, "too_large", "الرسالة أطول من المسموح.");
  let body: Record<string, unknown>;
  try {
    body = JSON.parse(text || "{}");
  } catch {
    return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");
  }
  if (typeof body !== "object" || body === null || Array.isArray(body)) return answer(400, "bad_json", "صيغة الطلب غير مقبولة.");

  // Only the chat's own fields go on, each of its own type.
  const message = typeof body.message === "string" ? body.message : null;
  const button = Number.isInteger(body.button) && (body.button as number) > 0 ? (body.button as number) : null;
  if (message === null && button === null) return answer(422, "empty", "اكتب سؤالك أو اختر زراً.");
  if (message !== null && [...message].length > 500) return answer(422, "not_allowed", "الرسالة أطول من 500 حرف.");
  const sent = {
    website: typeof body.website === "string" ? body.website : "",
    ...(button !== null ? { button } : { message }),
    conversation: typeof body.conversation === "string" && body.conversation.length <= 200 ? body.conversation : null,
    path: sitePath(body.path),
  };

  let result: { status: number; body: unknown };
  try {
    result = await apiPost("/chat/messages", sent, visitor);
  } catch (error) {
    console.error(`[site] chat: ${(error as Error).message}`);

    return answer(502, "unavailable", unavailable);
  }

  const data = (result.body as { data?: { conversation?: unknown; reply?: unknown } } | null)?.data;
  if (result.status === 200 && data && typeof data.conversation === "string") {
    return Response.json({ data: { conversation: data.conversation, reply: readReply(data.reply, serverEnv().siteUrl.origin) } }, { headers: noStore });
  }

  const error = (result.body as { error?: { code?: unknown; message?: unknown } } | null)?.error;
  const status = result.status >= 400 && result.status < 500 ? result.status : 502;

  return Response.json(
    { error: { code: typeof error?.code === "string" ? error.code : "unavailable", message: typeof error?.message === "string" ? error.message.slice(0, 300) : unavailable } },
    { status, headers: noStore },
  );
}

function answer(status: number, code: string, message: string) {
  return Response.json({ error: { code, message } }, { status, headers: noStore });
}
