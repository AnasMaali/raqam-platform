import Link from "next/link";

import { formatClock, formatDegrees } from "@/lib/format";
import type { City } from "@/lib/types";

import { WeatherChoice } from "./WeatherChoice";
import { WeatherIcon } from "./WeatherIcon";

/**
 * Today's weather in the default city, with a few other cities. Left out when no city has a reading. A reading the
 * platform marks as stale says when it was taken. The OpenWeather credit is always visible (the free plan's
 * condition).
 *
 * Drawn on the server, the same for every visitor (so it stays cached): one box for each city with a reading, the
 * default city's shown and the others `hidden` (out of the screen reader's and the keyboard's way). The list beside it
 * holds the default city and the next ones; the shown city's own line is hidden, so the list keeps its length. A city
 * the visitor chose (12.4, kept in this browser) is shown instead by WeatherChoice, which only moves the `hidden`
 * attributes. Every box has the same size: the name and the condition on one line, the line for an old reading always
 * kept.
 *
 * `id` names the widget; its boxes' ids start with it, so two widgets on one page never share an id.
 */
export function WeatherWidget({
  data,
  others = 6,
  headingLevel = 2,
  id = "weather",
}: {
  data: { cities: City[]; attribution: string } | null;
  others?: number;
  headingLevel?: 2 | 3;
  id?: string;
}) {
  const cities = data?.cities.filter((c) => c.now) ?? [];
  const shown = cities.find((c) => c.is_default) ?? cities[0];
  if (!data || !shown?.now) return null;
  const Heading = `h${headingLevel}` as const;
  // The shown city and the next ones, in the platform's order; the shown city's line is hidden.
  const listed = [shown, ...cities.filter((c) => c !== shown).slice(0, others)].sort((a, b) => cities.indexOf(a) - cities.indexOf(b));

  return (
    <section id={id} aria-label="الطقس" data-weather-widget className="border-t-2 border-brand pt-3">
      {cities.map((city, i) => (
        <div key={city.slug} role="group" aria-labelledby={`${id}-${i}-title`} data-city={city.slug} hidden={city !== shown}>
          <Heading id={`${id}-${i}-title`} className="truncate text-subheading font-bold text-brand">
            طقس {city.name}
          </Heading>
          <div className="mt-2 flex items-center gap-4">
            <WeatherIcon icon={city.now!.condition.icon} size={44} className="shrink-0 text-brand" />
            <p className="flex min-w-0 flex-col">
              <span dir="ltr" className="self-start text-figure font-bold">
                {formatDegrees(city.now!.temperature)}
              </span>
              <span className="truncate">{city.now!.condition.text}</span>
            </p>
          </div>
          <p className="mt-1 min-h-lh text-small text-muted">
            {city.now!.stale && (
              <>
                آخر قراءة الساعة <time dateTime={city.now!.observed_at}>{formatClock(city.now!.observed_at)}</time>
              </>
            )}
          </p>
        </div>
      ))}
      {listed.length > 1 && (
        <ul className="mt-3 grid grid-cols-2 gap-x-6 border-t border-line pt-1 text-small">
          {listed.map((c) => (
            <li key={c.slug} data-city-item={c.slug} hidden={c === shown}>
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
      <WeatherChoice widget={id} />
    </section>
  );
}
