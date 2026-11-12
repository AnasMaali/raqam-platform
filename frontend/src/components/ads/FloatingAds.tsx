"use client";

import { useEffect, useState, type ComponentType } from "react";

import type { FloatingAd } from "@/lib/ads";
import { whenIdle } from "@/lib/when-idle";

type Shown = ComponentType<{ ad: FloatingAd }>;

const interactions = ["scroll", "pointerdown", "keydown"] as const;

/**
 * The popup and the phone's bar (13.2). Their code loads only after the page has loaded and the browser is idle, and
 * only at the width each one shows at: the bar below 768 px, the popup from 1024 px. Neither can be or delay the page's
 * largest paint (LCP):
 * - the bar is drawn at the visitor's first scroll, press or key, when the browser stops looking for the largest
 *   paint (a picture arriving later on a page whose largest paint is text would otherwise become it). Its place, and
 *   the raised buttons above it, are kept by the server from the start (the body's data-ad-bar), so it moves nothing;
 * - the popup opens only after the first interaction too (components/ads/AdPopup).
 */
export function FloatingAds({ popup, bar }: { popup: FloatingAd | null; bar: FloatingAd | null }) {
  const [Bar, setBar] = useState<Shown | null>(null);
  const [Popup, setPopup] = useState<Shown | null>(null);

  useEffect(() => {
    let live = true;
    const stops: (() => void)[] = [];

    if (bar && matchMedia("(max-width: 47.99rem)").matches) {
      const interacted = () => {
        disarm();
        stops.push(whenIdle(() => import("./MobileAdBar").then((m) => live && setBar(() => m.MobileAdBar)).catch(() => undefined)));
      };
      const disarm = () => interactions.forEach((type) => window.removeEventListener(type, interacted, { capture: true }));
      interactions.forEach((type) => window.addEventListener(type, interacted, { capture: true, passive: true }));
      stops.push(disarm);
    }
    if (popup && matchMedia("(min-width: 64rem)").matches)
      stops.push(whenIdle(() => import("./AdPopup").then((m) => live && setPopup(() => m.AdPopup)).catch(() => undefined)));

    return () => {
      live = false;
      stops.forEach((stop) => stop());
    };
  }, [popup, bar]);

  return (
    <>
      {Bar && bar && <Bar ad={bar} />}
      {Popup && popup && <Popup ad={popup} />}
    </>
  );
}
