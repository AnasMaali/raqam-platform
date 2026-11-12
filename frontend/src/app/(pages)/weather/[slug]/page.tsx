import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { marketMetadata } from "@/components/markets/MarketsParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { WeatherIcon } from "@/components/weather/WeatherIcon";
import { RememberCity } from "@/components/weather/CityChoice";
import { CityNow, Forecast, WeatherCredit } from "@/components/weather/WeatherParts";
import { formatDegrees } from "@/lib/format";
import { getCityList, getCityReadings, getCityWeather } from "@/lib/server/markets";
import { cityStructuredData } from "@/lib/server/structured-data";

/*
 * One city's weather (page inventory: /weather/{slug}), «طقس نابلس اليوم»: now, today's low and high, humidity, wind
 * and five days, with the brand's day and night drawings. Slugs are Arabic. An unknown or hidden city is a 404
 * (src/proxy.ts says so before the page streams).
 */
export const instant = false;

export async function generateStaticParams() {
  const cities = await getCityList();

  // At least one: a route built ahead needs a real parameter (an unknown one builds as not found).
  return (cities.length ? cities.map((c) => c.slug) : ["-"]).map((slug) => ({ slug }));
}

type Params = PageProps<"/weather/[slug]">["params"];

function safeDecode(value: string) {
  try {
    return decodeURIComponent(value);
  } catch {
    return null;
  }
}

async function load(params: Params) {
  const slug = safeDecode((await params).slug);
  // The platform's other weather addresses are never a city's.
  if (slug === null || slug === "widget" || slug === "cities") return { status: "missing" as const };

  try {
    const [one, all] = await Promise.all([getCityWeather(slug), getCityReadings()]);
    if (!one) return { status: "missing" as const };

    return { status: "ok" as const, ...one, others: all.cities.filter((c) => c.slug !== one.city.slug) };
  } catch (error) {
    console.error(`[site] the weather of ${slug} is unavailable: ${(error as Error).message}`);

    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/weather/[slug]">) {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { city } = loaded;
  const now = city.now;

  return marketMetadata({
    title: `طقس ${city.name} اليوم`,
    description: now
      ? `طقس ${city.name} الآن: ${formatDegrees(now.temperature)} و${now.condition.text}${now.today ? `، الصغرى ${formatDegrees(now.today.min)} والعظمى ${formatDegrees(now.today.max)}` : ""}. الرطوبة والرياح وتوقعات الأيام الخمسة.`
      : `طقس ${city.name} اليوم وتوقعات الأيام الخمسة.`,
    path: city.path,
  });
}

export default async function CityWeatherPage({ params }: PageProps<"/weather/[slug]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الطقس الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { city, attribution, others } = loaded;
  const headline = `طقس ${city.name} اليوم`;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "الطقس", path: "/weather" },
    { name: city.name, path: city.path },
  ];

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <h1 className="mt-2 text-title font-bold">{headline}</h1>
        <div className="mt-3">
          <RememberCity slug={city.slug} name={city.name} />
        </div>
        <div className="mt-6 grid gap-x-12 gap-y-10 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
          <div className="flex flex-col gap-10">
            <CityNow city={city} />
            <Forecast days={city.forecast} />
            <WeatherCredit text={attribution} />
          </div>
          {others.length > 0 && (
            <section aria-labelledby="other-cities" className="border-t-2 border-brand pt-3">
              <h2 id="other-cities" className="text-subheading font-bold">
                مدن أخرى
              </h2>
              <ul className="mt-2 grid grid-cols-2 gap-x-6 text-small lg:grid-cols-1">
                {others.map((c) => (
                  <li key={c.slug} className="border-b border-line">
                    <Link href={c.path} className="flex min-h-11 items-center justify-between gap-2 hover:text-brand">
                      <span>{c.name}</span>
                      {c.now && (
                        <span className="inline-flex items-center gap-1.5">
                          <WeatherIcon icon={c.now.condition.icon} size={18} className="text-muted" />
                          <span className="sr-only">{c.now.condition.text}</span>
                          <span dir="ltr" className="figures">
                            {formatDegrees(c.now.temperature)}
                          </span>
                        </span>
                      )}
                    </Link>
                  </li>
                ))}
              </ul>
              <Link href="/weather" className="mt-2 inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
                طقس كل المدن
              </Link>
            </section>
          )}
        </div>
      </div>
      <JsonLd data={cityStructuredData(city, headline, crumbs)} />
    </>
  );
}
