"use client";

import { useEffect, useState, type ComponentType } from "react";

import type { FloatingAd } from "@/lib/ads";
import { whenIdle } from "@/lib/when-idle";

type Shown = ComponentType<{ ad: FloatingAd }>;

/**
 * The popup and the phone's bar (13.2). Their code loads only after the page has loaded and the browser is idle, and
 * only at the width each one shows at: the bar below 768 px, the popup from 1024 px. Nothing of either is in the first
 * paint, so neither can delay it. The bar's place (and the raised buttons above it) is kept by the server from the
 * start (the body's data-ad-bar), so its arrival moves nothing.
 */
export function FloatingAds({ popup, bar }: { popup: FloatingAd | null; bar: FloatingAd | null }) {
  const [Bar, setBar] = useState<Shown | null>(null);
  const [Popup, setPopup] = useState<Shown | null>(null);

  useEffect(() => {
    let live = true;
    const stop = whenIdle(() => {
      if (bar && matchMedia("(max-width: 47.99rem)").matches)
        import("./MobileAdBar").then((m) => live && setBar(() => m.MobileAdBar)).catch(() => undefined);
      if (popup && matchMedia("(min-width: 64rem)").matches)
        import("./AdPopup").then((m) => live && setPopup(() => m.AdPopup)).catch(() => undefined);
    });

    return () => {
      live = false;
      stop();
    };
  }, [popup, bar]);

  return (
    <>
      {Bar && bar && <Bar ad={bar} />}
      {Popup && popup && <Popup ad={popup} />}
    </>
  );
}
