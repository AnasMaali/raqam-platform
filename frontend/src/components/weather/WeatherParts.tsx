import Link from "next/link";

import { formatClock, formatDayMonth, formatDegrees, formatWeekday } from "@/lib/format";
import { windFrom } from "@/lib/markets";
import type { City, CityWeather } from "@/lib/types";

import { WeatherIcon } from "./WeatherIcon";

/* The parts of the weather pages: a city's reading, its days ahead, every city, and the source's credit. */

/** "Weather data © OpenWeather", always visible where weather is shown (the free plan's condition). */
export function WeatherCredit({ text }: { text: string }) {
  return (
    <p dir="ltr" className="text-end text-tiny text-muted">
      {text}
    </p>
  );
}

/** When the reading was taken; an old one says so. */
function Observed({ now }: { now: NonNullable<City["now"]> }) {
  return (
    <p className={`text-small ${now.stale ? "font-medium" : "text-muted"}`}>
      {now.stale ? "آخر قراءة" : "قراءة"} الساعة <time dateTime={now.observed_at}>{formatClock(now.observed_at)}</time>
    </p>
  );
}

/** A temperature, kept left to right with its real minus sign. */
const Degrees = ({ value, className = "" }: { value: number; className?: string }) => (
  <span dir="ltr" className={className}>
    {formatDegrees(value)}
  </span>
);

/** The city now: the condition drawn and written, the temperature, how it feels, today's low and high, humidity, wind. */
export function CityNow({ city }: { city: CityWeather }) {
  const now = city.now;
  if (!now) return <p className="text-lead text-muted">لا قراءة لهذه المدينة بعد.</p>;
  const wind = windFrom(now.wind_deg);

  return (
    <section aria-label="الطقس الآن" className="border-y border-line py-5">
      <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
        <WeatherIcon icon={now.condition.icon} size={72} className="shrink-0 text-brand" />
        <div>
          <p className="flex flex-wrap items-baseline gap-x-3">
            <Degrees value={now.temperature} className="text-figure font-bold" />
            <span className="text-subheading">{now.condition.text}</span>
          </p>
          {now.feels_like !== null && (
            <p className="text-small text-muted">
              تشعر وكأنها <Degrees value={now.feels_like} />
            </p>
          )}
          <Observed now={now} />
        </div>
      </div>
      <dl className="mt-5 grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
        {now.today && (
          <div>
            <dt className="text-small text-muted">الصغرى والعظمى اليوم</dt>
            <dd className="text-subheading font-bold">
              <Degrees value={now.today.min} /> <span className="text-muted">/</span> <Degrees value={now.today.max} />
            </dd>
          </div>
        )}
        {now.humidity !== null && (
          <div>
            <dt className="text-small text-muted">الرطوبة</dt>
            <dd className="text-subheading font-bold">
              <span dir="ltr">{now.humidity}%</span>
            </dd>
          </div>
        )}
        {now.wind_kmh !== null && (
          <div>
            <dt className="text-small text-muted">الرياح</dt>
            <dd className="text-subheading font-bold">
              <span dir="ltr">{Math.round(now.wind_kmh)}</span> كم/س{wind && <span className="block text-small font-normal text-muted">{wind}</span>}
            </dd>
          </div>
        )}
      </dl>
    </section>
  );
}

/** The days ahead (the platform gives five, today first), each with its condition, its high and its low. */
export function Forecast({ days }: { days: CityWeather["forecast"] }) {
  if (days.length === 0) return null;

  return (
    <section aria-labelledby="forecast-title">
      <h2 id="forecast-title" className="text-heading font-bold">
        توقعات {days.length === 5 ? "الأيام الخمسة" : `${days.length} أيام`}
      </h2>
      <ol className="mt-3 grid sm:grid-cols-5 sm:gap-4">
        {days.map((day) => (
          <li key={day.date} className="flex min-h-14 items-center gap-3 border-b border-line py-2 sm:flex-col sm:items-start sm:gap-2 sm:border-b-0 sm:border-t-2 sm:border-t-line sm:pt-3">
            <p className="min-w-24 sm:min-w-0">
              <span className="block font-medium">{formatWeekday(day.date)}</span>
              <time dateTime={day.date} className="block text-small text-muted">
                {formatDayMonth(day.date)}
              </time>
            </p>
            <WeatherIcon icon={day.condition.icon} size={32} className="shrink-0 text-brand" />
            <p className="flex-1 text-small">{day.condition.text}</p>
            <p className="flex items-baseline gap-2 whitespace-nowrap">
              <span className="sr-only">العظمى</span>
              <Degrees value={day.max} className="font-bold" />
              <span className="sr-only">والصغرى</span>
              <Degrees value={day.min} className="text-muted" />
            </p>
          </li>
        ))}
      </ol>
    </section>
  );
}

/** Every city with its reading, each card linking to the city's page; the card's name is the one link. */
export function CityGrid({ cities, headingLevel = 2 }: { cities: City[]; headingLevel?: 2 | 3 }) {
  const Heading = `h${headingLevel}` as const;

  return (
    <ul className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3 lg:grid-cols-4">
      {cities.map((city) => (
        <li key={city.slug} className="relative flex flex-col gap-1 border-t-2 border-line pt-3 has-[a:hover]:border-brand">
          <Heading className="text-subheading font-bold">
            <Link href={city.path} className="after:absolute after:inset-0 hover:text-brand">
              طقس {city.name}
            </Link>
          </Heading>
          {city.now ? (
            <>
              <p className="flex items-center gap-2">
                <WeatherIcon icon={city.now.condition.icon} size={32} className="shrink-0 text-brand" />
                <Degrees value={city.now.temperature} className="text-heading font-bold" />
              </p>
              <p className="text-small">{city.now.condition.text}</p>
              {city.now.stale && <Observed now={city.now} />}
            </>
          ) : (
            <p className="text-small text-muted">لا قراءة بعد</p>
          )}
        </li>
      ))}
    </ul>
  );
}
