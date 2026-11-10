"use client";

import { usePathname } from "next/navigation";
import { useEffect } from "react";

/*
 * The site's own statistics (decision 22), without cookies and without a library. After the page has loaded and the
 * browser is idle, it reports the page view (or a missing page) to the site's route, which adds what only the server
 * knows (the visitor's address, the post's id) and passes it on. The ads on the page are reported once each, when at
 * least half of one is on screen (13.6), in batches. A new address (a client navigation) is a new view with a new view
 * id. Reports go with sendBeacon, so leaving the page never loses one and never waits for one.
 */

function send(url: string, body: unknown) {
  const text = JSON.stringify(body);
  try {
    if (navigator.sendBeacon?.(url, new Blob([text], { type: "text/plain;charset=UTF-8" }))) return;
  } catch {
    // Some browsers refuse a beacon; the request below takes its place.
  }
  void fetch(url, { method: "POST", body: text, keepalive: true, headers: { "Content-Type": "text/plain;charset=UTF-8" } }).catch(() => undefined);
}

function whenIdle(task: () => void): () => void {
  let cancelled = false;
  const run = () => {
    if (cancelled) return;
    if ("requestIdleCallback" in window) window.requestIdleCallback(() => !cancelled && task(), { timeout: 3000 });
    else setTimeout(() => !cancelled && task(), 200);
  };
  if (document.readyState === "complete") run();
  else window.addEventListener("load", run, { once: true });

  return () => {
    cancelled = true;
    window.removeEventListener("load", run);
  };
}

const viewId = () => (crypto.randomUUID?.() ?? `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, "").slice(0, 64);

export function ViewReporter() {
  const pathname = usePathname();

  useEffect(() => {
    const view = viewId();
    const seen = new Set<string>();
    const queue: { campaign_id: number; placement: string }[] = [];
    let timer: ReturnType<typeof setTimeout> | null = null;
    let watcher: IntersectionObserver | null = null;

    const flush = () => {
      timer = null;
      while (queue.length) send("/api/ads/impressions", { view_id: view, items: queue.splice(0, 8) });
    };

    const stop = whenIdle(() => {
      const missing = !!document.querySelector("[data-page-status='not-found']");
      send("/api/stats", { type: missing ? "not_found" : "view", path: `${location.pathname}${location.search}`, referrer: document.referrer || null });

      watcher = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const el = entry.target as HTMLElement;
            const key = `${el.dataset.adCampaign}:${el.dataset.adPlacement}`;
            watcher?.unobserve(el);
            if (seen.has(key)) continue;
            seen.add(key);
            queue.push({ campaign_id: Number(el.dataset.adCampaign), placement: el.dataset.adPlacement ?? "" });
            timer ??= setTimeout(flush, 1000);
          }
        },
        { threshold: 0.5 },
      );
      // A slot hidden at this width (display: none) is never on screen, so it is never reported.
      document.querySelectorAll<HTMLElement>("[data-ad-campaign]").forEach((el) => watcher!.observe(el));
    });

    // Leaving the page sends what was seen and not sent yet.
    const leaving = () => {
      if (document.visibilityState === "hidden" && timer) {
        clearTimeout(timer);
        flush();
      }
    };
    document.addEventListener("visibilitychange", leaving);

    return () => {
      stop();
      watcher?.disconnect();
      document.removeEventListener("visibilitychange", leaving);
      if (timer) {
        clearTimeout(timer);
        flush();
      }
    };
  }, [pathname]);

  return null;
}
