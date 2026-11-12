"use client";

import { useEffect, useRef } from "react";

import { announceAd, type FloatingAd } from "@/lib/ads";

/**
 * The bar at the bottom of a phone's screen (13.2), below 768 px only. It sits in the place the server kept for it
 * (the body's padding and the raised buttons, globals.css), above the phone's own bar (safe-area-inset-bottom). It
 * says «إعلان», and its picture keeps the slot's shape, smaller on a screen too narrow for it.
 */
export function MobileAdBar({ ad }: { ad: FloatingAd }) {
  const link = useRef<HTMLAnchorElement>(null);

  useEffect(() => {
    if (ad.campaign) announceAd(link.current);
  }, [ad.campaign]);

  return (
    <aside
      aria-label={ad.label}
      data-ad-bar-box
      className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-ground pb-[env(safe-area-inset-bottom)] md:hidden"
    >
      {/* Its top line is part of the kept height. */}
      <div className="flex h-[calc(var(--ad-bar-h)-1px)] items-center justify-center gap-1.5 px-2">
        <p className="shrink-0 text-tiny text-muted">{ad.label}</p>
        <a
          ref={link}
          href={ad.href}
          rel={ad.rel}
          style={{ width: ad.size.width, aspectRatio: `${ad.size.width} / ${ad.size.height}` }}
          className="block min-w-0 max-w-full shrink overflow-hidden bg-surface"
          {...(ad.campaign ? { "data-ad-campaign": ad.campaign, "data-ad-placement": ad.placement } : {})}
        >
          {/* eslint-disable-next-line @next/next/no-img-element -- the advertiser's picture, already sized by the platform */}
          <img src={ad.image.url} alt={ad.alt} width={ad.image.width} height={ad.image.height} decoding="async" className="size-full object-cover" />
        </a>
      </div>
    </aside>
  );
}
