import { apiPost } from "@/lib/server/api";
import { sameOrigin, visitorOf } from "@/lib/server/visitor";

/*
 * A press on a service's contact button (14.9), reported by the page and counted by the platform with the visitor's
 * address, which only this server holds. Always 204 for the page: counting never stands in the visitor's way.
 *
 *   POST /api/services/{slug}/contact  { channel: "whatsapp" | "phone" | "email" }
 */

const channels = new Set(["whatsapp", "phone", "email"]);
const noContent = () => new Response(null, { status: 204, headers: { "Cache-Control": "no-store, private" } });

export async function POST(request: Request, { params }: RouteContext<"/api/services/[slug]/contact">) {
  if (!sameOrigin(request)) return Response.json({ error: { code: "site_only", message: "من صفحات الموقع فقط." } }, { status: 403 });
  const visitor = visitorOf(request.headers);
  const text = await request.text();
  if (!visitor || text.length > 256) return noContent();
  let channel: unknown;
  try {
    channel = (JSON.parse(text) as { channel?: unknown }).channel;
  } catch {
    return noContent();
  }
  const { slug } = await params;
  if (typeof channel !== "string" || !channels.has(channel) || slug.length > 200) return noContent();

  try {
    await apiPost(`/services/${encodeURIComponent(decodeURIComponent(slug))}/contact`, { channel }, visitor);
  } catch (error) {
    console.error(`[site] a service contact press was not counted: ${(error as Error).message}`);
  }

  return noContent();
}
