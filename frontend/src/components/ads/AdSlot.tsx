import type { CSSProperties } from "react";

import type { AdImage, AdSlot as Slot } from "@/lib/types";

function Banner({ desktop, mobile, alt, eager }: { desktop: AdImage | null; mobile: AdImage | null; alt: string; eager: boolean }) {
  const main = desktop ?? mobile;
  if (!main) return null;

  return (
    <picture>
      {desktop && mobile && <source media="(max-width: 47.99rem)" srcSet={mobile.url} />}
      <img src={main.url} alt={alt} width={main.width} height={main.height} loading={eager ? "eager" : "lazy"} decoding="async" className="size-full object-cover" />
    </picture>
  );
}

/**
 * An ad slot, with the campaign the server picked for it (the first of `slot.ads`). It always says «إعلان», and its box keeps the slot's size before the picture arrives (the phone size
 * below 768 px, the desktop size above), so nothing below it moves. A slot with no campaign shows the platform's own
 * «أعلن معنا» banner when it has one; otherwise it renders nothing and reserves nothing. Clicks go through the
 * platform's counting link.
 */
export function AdSlot({ slot, label = "إعلان", className = "" }: { slot: Slot | undefined | null; label?: string; className?: string }) {
  if (!slot || !slot.active) return null;
  const { desktop, mobile } = slot.sizes;
  if (!desktop && !mobile) return null;
  const campaign = slot.ads[0] ?? null;
  const house = !campaign && slot.empty === "house" ? slot.house : null;
  if (!campaign && !house) return null;

  const phone = mobile ?? desktop!;
  const wide = desktop ?? mobile!;
  const box = {
    "--ad-phone-w": `${phone.width}px`,
    "--ad-phone-r": `${phone.width} / ${phone.height}`,
    "--ad-wide-w": `${wide.width}px`,
    "--ad-wide-r": `${wide.width} / ${wide.height}`,
  } as CSSProperties;
  const visibility = !desktop ? "md:hidden" : !mobile ? "max-md:hidden" : "";
  const images = campaign?.images ?? house!.images;
  const alt = campaign ? `إعلان: ${campaign.advertiser}` : "أعلن معنا في رقم";

  return (
    <aside aria-label={label} className={`${visibility} ${className}`}>
      <p className="text-tiny text-muted">{label}</p>
      <a
        href={campaign ? campaign.click_url : house!.target}
        rel={campaign ? campaign.rel : undefined}
        style={box}
        className="mt-1 block w-full max-w-(--ad-phone-w) overflow-hidden bg-surface aspect-(--ad-phone-r) md:max-w-(--ad-wide-w) md:aspect-(--ad-wide-r)"
      >
        <Banner desktop={images.desktop} mobile={images.mobile} alt={alt} eager={!slot.lazy} />
      </a>
    </aside>
  );
}
