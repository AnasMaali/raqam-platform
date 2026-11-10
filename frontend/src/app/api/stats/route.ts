import { apiPost } from "@/lib/server/api";
import { beaconBody, received } from "@/lib/server/beacon";
import { visitorOf } from "@/lib/server/visitor";

/*
 * A page view or a missing page (22.1–22.10), reported by the page after it loaded, passed on to the platform with what
 * only this server holds: the site token and the visitor's address and browser. The post's id comes from the address
 * here, never from the browser. Always 204.
 *
 *   POST /api/stats  { type: "view" | "not_found", path, referrer }
 */

const postAddress = /^\/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)\/([1-9]\d{0,9})(?:\/|$)/;

export async function POST(request: Request) {
  const body = await beaconBody(request);
  const visitor = visitorOf(request.headers);
  if (!body || !visitor) return received();
  const { type, path, referrer } = body;
  if ((type !== "view" && type !== "not_found") || typeof path !== "string" || path.length > 2000 || !/^\/(?!\/)\S*$/.test(path)) return received();
  const from = typeof referrer === "string" && /^https?:\/\//.test(referrer) ? referrer.slice(0, 2000) : null;
  const post = type === "view" ? postAddress.exec(path.split("?")[0]) : null;

  try {
    await apiPost("/stats/hits", { type, path, referrer: from, post_id: post ? Number(post[2]) : null }, visitor);
  } catch (error) {
    console.error(`[site] a page view was not reported: ${(error as Error).message}`);
  }

  return received();
}
