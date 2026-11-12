import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { marketMetadata } from "@/components/markets/MarketsParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { NearestCity } from "@/components/weather/CityChoice";
import { CityGrid, WeatherCredit } from "@/components/weather/WeatherParts";
import { getCityReadings } from "@/lib/server/markets";
import { weatherStructuredData } from "@/lib/server/structured-data";

/* Every city's weather now (page inventory: /weather), each leading to the city's own page. */
export const instant = false;

const headline = "طقس فلسطين اليوم";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "الطقس", path: "/weather" },
];

async function load() {
  try {
    return await getCityReadings();
  } catch (error) {
    console.error(`[site] the weather is unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const loaded = await load();
  const names = loaded?.cities.slice(0, 4).map((c) => c.name) ?? [];

  return marketMetadata({
    title: names.length ? `${headline}: درجات الحرارة في ${names.join(" و")} وباقي المدن` : headline,
    description: `حالة الطقس ودرجات الحرارة الآن في ${loaded ? `${loaded.cities.length} مدينة فلسطينية` : "المدن الفلسطينية"}${names.length ? `، منها ${names.join(" و")}` : ""}، مع توقعات الأيام الخمسة لكل مدينة.`,
    path: "/weather",
  });
}

export default async function WeatherPage() {
  const loaded = await load();
  if (!loaded) {
    // Rendered for this request only, so a passing failure is never kept as the page.
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الطقس الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { cities, attribution } = loaded;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2">
          <h1 className="text-title font-bold">{headline}</h1>
          <p className="mt-2 max-w-text text-lead text-muted">الطقس الآن في كل مدينة. افتح المدينة لترى الصغرى والعظمى والرطوبة والرياح وتوقعات الأيام الخمسة.</p>
          <div className="mt-4">
            <NearestCity
              cities={cities.flatMap((c) => (c.latitude !== undefined && c.longitude !== undefined ? [{ slug: c.slug, path: c.path, name: c.name, latitude: c.latitude, longitude: c.longitude }] : []))}
            />
          </div>
        </header>
        <h2 className="sr-only">المدن</h2>
        <div className="mt-8">
          <CityGrid cities={cities} headingLevel={3} />
        </div>
        <div className="mt-8 border-t border-line pt-2">
          <WeatherCredit text={attribution} />
        </div>
      </div>
      <JsonLd data={weatherStructuredData(cities, headline, crumbs)} />
    </>
  );
}
