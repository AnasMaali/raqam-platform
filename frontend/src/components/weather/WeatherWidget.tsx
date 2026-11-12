"use client";

import Link from "next/link";
import { useSyncExternalStore } from "react";

import { formatClock, formatDegrees } from "@/lib/format";
import type { City } from "@/lib/types";

import { cityStore } from "./city-choice";
import { WeatherIcon } from "./WeatherIcon";

/**
 * Today's weather in the default city, with a few other cities. Left out when no city has a reading. A reading the
 * platform marks as stale says when it was taken. The OpenWeather credit is always visible (the free plan's
 * condition).
 *
 * The page is drawn with the default city (the same for every visitor, so it stays cached); a city the visitor chose
 * (12.4, kept in this browser) takes its place once the page shows. Its box keeps the same size whatever the city: the
 * name and the condition stay on one line, the line for an old reading is always kept, and the list beside it keeps
 * its length (the default city takes the chosen one's place in it).
 */
export function WeatherWidget({
  data,
  others = 6,
  headingLevel = 2,
}: {
  data: { cities: City[]; attribution: string } | null;
  others?: number;
  headingLevel?: 2 | 3;
}) {
  const chosen = useSyncExternalStore(cityStore.subscribe, cityStore.get, cityStore.server);
  const cities = data?.cities.filter((c) => c.now) ?? [];
  const city = cities.find((c) => c.slug === chosen) ?? cities.find((c) => c.is_default) ?? cities[0];
  if (!data || !city?.now) return null;
  const Heading = `h${headingLevel}` as const;
  const rest = cities.filter((c) => c !== city).slice(0, others);

  return (
    <section aria-labelledby="weather-title" className="border-t-2 border-brand pt-3">
      <Heading id="weather-title" className="truncate text-subheading font-bold text-brand">
        طقس {city.name}
      </Heading>
      <div className="mt-2 flex items-center gap-4">
        <WeatherIcon icon={city.now.condition.icon} size={44} className="shrink-0 text-brand" />
        <p className="flex min-w-0 flex-col">
          <span dir="ltr" className="self-start text-figure font-bold">
            {formatDegrees(city.now.temperature)}
          </span>
          <span className="truncate">{city.now.condition.text}</span>
        </p>
      </div>
      <p className="mt-1 min-h-lh text-small text-muted">
        {city.now.stale && (
          <>
            آخر قراءة الساعة <time dateTime={city.now.observed_at}>{formatClock(city.now.observed_at)}</time>
          </>
        )}
      </p>
      {rest.length > 0 && (
        <ul className="mt-3 grid grid-cols-2 gap-x-6 border-t border-line pt-1 text-small">
          {rest.map((c) => (
            <li key={c.slug}>
              <Link href={c.path} className="flex min-h-11 items-center justify-between gap-2 hover:text-brand">
                <span>{c.name}</span>
                <span className="inline-flex items-center gap-1.5">
                  <WeatherIcon icon={c.now!.condition.icon} size={18} className="text-muted" />
                  <span className="sr-only">{c.now!.condition.text}</span>
                  <span dir="ltr" className="figures">
                    {formatDegrees(c.now!.temperature)}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4">
        <Link href="/weather" className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
          طقس كل المدن
        </Link>
        <p dir="ltr" className="text-tiny text-muted">
          {data.attribution}
        </p>
      </div>
    </section>
  );
}
