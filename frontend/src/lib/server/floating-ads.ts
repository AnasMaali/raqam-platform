import "server-only";

import type { FloatingAd } from "@/lib/ads";
import type { AdSlot, Site } from "@/lib/types";

import type { getAds } from "./site-data";

const switches = { popup: "ad_popup", mobile_sticky: "ad_mobile_sticky" } as const;

/** A popup slot without its frequency (the column allows it) shows at most once a day, the platform's default. */
const defaultCapHours = 24;

function floating(slot: AdSlot | undefined, site: Site | null, label: string): FloatingAd | null {
  if (!slot || !slot.active) return null;
  const placement = slot.code as FloatingAd["placement"];
  // Both need the platform's switch as well as the slot (13.2); each exists on one device only.
  if (!site?.features?.[switches[placement]]) return null;
  const size = placement === "popup" ? slot.sizes.desktop : slot.sizes.mobile;
  if (!size) return null;
  const campaign = slot.ads[0] ?? null;
  const house = !campaign && slot.empty === "house" ? slot.house : null;
  if (!campaign && !house) return null;
  const images = campaign?.images ?? house!.images;
  const picture = placement === "popup" ? (images.desktop ?? images.mobile) : (images.mobile ?? images.desktop);
  if (!picture) return null;

  return {
    placement,
    campaign: campaign?.id ?? null,
    href: campaign ? campaign.click_url : house!.target,
    ...(campaign ? { rel: campaign.rel } : {}),
    image: { url: picture.url, width: picture.width, height: picture.height },
    alt: campaign ? `إعلان: ${campaign.advertiser}` : "أعلن معنا في رقم",
    label,
    size: { width: size.width, height: size.height },
    capHours: slot.frequency_cap_hours && slot.frequency_cap_hours > 0 ? slot.frequency_cap_hours : defaultCapHours,
  };
}

/** The popup and the phone's bar for this cache period, or null for each that is off or has nothing to show. */
export function floatingAds(ads: Awaited<ReturnType<typeof getAds>>, site: Site | null) {
  const label = ads?.label ?? "إعلان";
  const slot = (code: string) => ads?.slots.find((s) => s.code === code);

  return { popup: floating(slot("popup"), site, label), bar: floating(slot("mobile_sticky"), site, label) };
}
