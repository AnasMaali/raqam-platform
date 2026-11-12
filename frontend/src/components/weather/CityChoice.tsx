"use client";

import { useRouter } from "next/navigation";
import { useState, useSyncExternalStore } from "react";

import { Icon } from "../ui/Icon";

import { cityStore, nearestCity, rememberCity, type Place } from "./city-choice";

/*
 * The visitor's city (12.4, 12.5), in the browser only. Each control sits in a box of its own fixed height, so a
 * message replacing the button moves nothing on the page.
 */

const box = "flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1";
const button = "inline-flex min-h-11 items-center gap-2 rounded-sm border border-line px-4 font-medium hover:border-brand hover:text-brand disabled:opacity-60";

/**
 * «أقرب مدينة لي»: the position is asked for only on the press, the nearest city is found here, remembered, and its page
 * opens. The position itself is never kept or sent.
 */
export function NearestCity({ cities }: { cities: Place[] }) {
  const router = useRouter();
  const [state, setState] = useState<"idle" | "asking" | "denied" | "failed" | "unsupported">("idle");
  if (cities.length === 0) return null;

  const find = () => {
    if (!("geolocation" in navigator)) {
      setState("unsupported");

      return;
    }
    setState("asking");
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        const city = nearestCity(cities, { latitude: coords.latitude, longitude: coords.longitude });
        if (!city) return setState("failed");
        rememberCity(city.slug);
        router.push(city.path);
      },
      (error) => setState(error.code === error.PERMISSION_DENIED ? "denied" : "failed"),
      { enableHighAccuracy: false, timeout: 15_000, maximumAge: 600_000 },
    );
  };
  const message = {
    idle: "",
    asking: "نحدّد أقرب مدينة…",
    denied: "لم تسمح بمعرفة موقعك. اختر مدينتك من القائمة.",
    failed: "تعذّر تحديد موقعك الآن. اختر مدينتك من القائمة.",
    unsupported: "متصفحك لا يحدّد الموقع. اختر مدينتك من القائمة.",
  }[state];

  // The answer can come seconds after the press (the browser asks the visitor first): its line is kept from the start.
  return (
    <div className="flex flex-col items-start gap-1">
      <button type="button" onClick={find} disabled={state === "asking"} className={button}>
        <Icon name="pin" size={18} />
        أقرب مدينة لي
      </button>
      <p role="status" className="min-h-lh text-small text-muted">
        {message}
      </p>
    </div>
  );
}

/** «اجعلها مدينتي» on a city's page: the home page then shows this city first. */
export function RememberCity({ slug, name }: { slug: string; name: string }) {
  const mine = useSyncExternalStore(cityStore.subscribe, cityStore.get, cityStore.server) === slug;
  const [state, setState] = useState<"idle" | "saved" | "blocked">("idle");

  return (
    <div className={box}>
      {mine ? (
        <p role="status" className="inline-flex min-h-11 items-center gap-2 font-medium text-brand">
          <Icon name="pin" size={18} />
          {state === "saved" ? `حفظنا ${name} مدينتك في الرئيسية.` : `${name} مدينتك المحفوظة.`}
        </p>
      ) : (
        <>
          <button type="button" className={button} onClick={() => setState(rememberCity(slug) ? "saved" : "blocked")}>
            <Icon name="pin" size={18} />
            اجعلها مدينتي
          </button>
          <p role="status" className="text-small text-muted">
            {state === "blocked" ? "لا يسمح هذا المتصفح بحفظ اختيارك." : ""}
          </p>
        </>
      )}
    </div>
  );
}
