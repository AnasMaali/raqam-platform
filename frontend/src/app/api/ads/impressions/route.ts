import { apiPost } from "@/lib/server/api";
import { beaconBody, received } from "@/lib/server/beacon";
import { visitorOf } from "@/lib/server/visitor";

/*
 * The ads a visitor saw in one page view (13.6): at least half on screen, once each, up to 8 in a report. Passed on
 * to the platform with the site token and the visitor's address and browser, which only this server holds. Always 204.
 *
 *   POST /api/ads/impressions  { view_id, items: [{ campaign_id, placement }] }
 */

export async function POST(request: Request) {
  const body = await beaconBody(request);
  const visitor = visitorOf(request.headers);
  if (!body || !visitor) return received();
  const { view_id: view, items } = body;
  if (typeof view !== "string" || !/^[A-Za-z0-9_-]{8,64}$/.test(view) || !Array.isArray(items) || items.length < 1 || items.length > 8) return received();
  const clean = items.flatMap((item) => {
    const { campaign_id: id, placement } = (item ?? {}) as Record<string, unknown>;
    return Number.isSafeInteger(id) && (id as number) > 0 && typeof placement === "string" && /^[a-z_]{2,40}$/.test(placement) ? [{ campaign_id: id as number, placement }] : [];
  });
  if (clean.length !== items.length) return received();

  try {
    await apiPost("/ads/impressions", { view_id: view, items: clean }, visitor);
  } catch (error) {
    console.error(`[site] ad views were not reported: ${(error as Error).message}`);
  }

  return received();
}
