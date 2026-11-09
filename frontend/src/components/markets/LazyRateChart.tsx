"use client";

import dynamic from "next/dynamic";
import { useEffect, useRef, useState } from "react";

// Its own chunk: Recharts is fetched by this page only, and only when the chart is about to be seen.
const RateChart = dynamic(() => import("./RateChart"), { ssr: false });

/**
 * The chart's reserved box (its height is fixed, so nothing below it moves) and the chart, loaded when the box comes
 * within 100 px of the screen. Hidden from screen readers: the caption and the table beside it carry the same figures.
 */
export function LazyRateChart({ points, decimals }: { points: { date: string; mid: string }[]; decimals: number }) {
  const box = useRef<HTMLDivElement>(null);
  const [near, setNear] = useState(false);

  useEffect(() => {
    const element = box.current;
    if (!element) return;
    const watcher = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) {
          setNear(true);
          watcher.disconnect();
        }
      },
      { rootMargin: "100px 0px" },
    );
    watcher.observe(element);

    return () => watcher.disconnect();
  }, []);

  return (
    <div ref={box} aria-hidden="true" className="h-60 sm:h-72">
      {near && <RateChart points={points} decimals={decimals} />}
    </div>
  );
}
