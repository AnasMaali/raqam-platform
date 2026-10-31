"use client";

import { useEffect, useRef, useState } from "react";

import { Icon } from "../ui/Icon";

/**
 * «للأعلى»: appears once the reader is a screen and a half down, and goes back to the header. While hidden it is out
 * of the tab order. It fades in; with reduced motion it simply appears.
 */
export function BackToTop() {
  const marker = useRef<HTMLDivElement>(null);
  const [shown, setShown] = useState(false);

  useEffect(() => {
    const node = marker.current;
    if (!node) return;
    // The marker covers the first screen and a half: the button shows once none of it is on screen. A marker of one
    // pixel would miss a jump (an anchor, a reload half way down) that never crosses it.
    const observer = new IntersectionObserver(([entry]) => setShown(!entry.isIntersecting));
    observer.observe(node);

    return () => observer.disconnect();
  }, []);

  return (
    <>
      <div ref={marker} aria-hidden="true" className="pointer-events-none absolute top-0 h-[150svh] w-px" />
      <a
        href="#top"
        data-site-frame
        className={`fixed end-4 bottom-[max(1rem,env(safe-area-inset-bottom))] z-40 inline-flex size-12 items-center justify-center rounded-sm border border-line-strong bg-ground text-brand shadow-raised transition-opacity duration-200 ease-out motion-reduce:transition-none ${shown ? "opacity-100" : "invisible opacity-0"}`}
      >
        <Icon name="up" />
        <span className="sr-only">للأعلى</span>
      </a>
    </>
  );
}
