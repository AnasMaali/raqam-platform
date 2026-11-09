import { revalidateTag } from "next/cache";

import { tagsFor } from "@/lib/server/cache-tags";
import { maxBodyBytes, verifyWebhook } from "@/lib/server/webhook";

/**
 * The platform tells the site that content changed (SITE_WEBHOOK_URL in the backend points here). Only a message
 * signed with SITE_WEBHOOK_SECRET, at most five minutes old, is accepted; it refreshes the cached answers its type
 * names, at once: the next visitor gets fresh data (a post taken down must not be served again).
 */
export async function POST(request: Request) {
  const length = Number(request.headers.get("content-length") ?? 0);
  if (length > maxBodyBytes) return refuse(413);

  const body = await request.text();
  if (Buffer.byteLength(body) > maxBodyBytes) return refuse(413);

  const verdict = verifyWebhook({
    secret: process.env.SITE_WEBHOOK_SECRET?.trim() || undefined,
    timestamp: request.headers.get("x-raqam-timestamp"),
    signature: request.headers.get("x-raqam-signature"),
    body,
  });
  if (!verdict.ok) {
    if (verdict.reason === "no_secret") console.error("[site] webhook refused: SITE_WEBHOOK_SECRET is not set");

    return refuse(verdict.reason === "no_secret" ? 503 : 401);
  }

  let message: { type?: unknown; id?: unknown };
  try {
    message = JSON.parse(body);
  } catch {
    return refuse(400);
  }
  if (typeof message !== "object" || message === null || typeof message.type !== "string") return refuse(400);

  const refreshed = tagsFor(message.type, message.id);
  for (const tag of refreshed) revalidateTag(tag, { expire: 0 });

  return Response.json({ refreshed }, { headers: { "Cache-Control": "no-store" } });
}

/** One short answer for every refusal: it says nothing about which check failed. */
function refuse(status: 400 | 401 | 413 | 503) {
  return Response.json({ error: { code: "refused" } }, { status, headers: { "Cache-Control": "no-store" } });
}
