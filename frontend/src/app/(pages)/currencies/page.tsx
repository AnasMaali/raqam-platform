import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { Converter } from "@/components/markets/Converter";
import { CurrencyTable, RateNotes, RatesStamp, marketMetadata } from "@/components/markets/MarketsParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { formatDay } from "@/lib/format";
import { definiteName } from "@/lib/markets";
import { getConverter, getCurrencies } from "@/lib/server/markets";
import { currenciesStructuredData } from "@/lib/server/structured-data";

/*
 * Today's rates against the shekel (page inventory: /currencies): buy, sell and the change of every visible currency,
 * the converter, «آخر تحديث» and the platform's note that the rates are indicative. Each currency has its own page.
 */
export const instant = false;

const headline = "أسعار العملات مقابل الشيكل اليوم";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "أسعار العملات", path: "/currencies" },
];

async function load() {
  try {
    return await Promise.all([getCurrencies(), getConverter()]);
  } catch (error) {
    console.error(`[site] the currency board is unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const loaded = await load();
  const names = loaded?.[0].rates.slice(0, 3).map((r) => definiteName(r.code, r.name)) ?? [];
  const day = loaded?.[0].meta.rate_date;

  return marketMetadata({
    title: names.length ? `أسعار العملات اليوم مقابل الشيكل: ${names.join(" و")}` : headline,
    description: `أسعار صرف العملات مقابل الشيكل${day ? ` ليوم ${formatDay(day)}` : " اليوم"}: الشراء والبيع والتغيّر${names.length ? ` لـ${names.join(" و")} وغيرها` : ""}، مع محوّل العملات. أسعار استرشادية.`,
    path: "/currencies",
  });
}

export default async function CurrenciesPage() {
  const loaded = await load();
  if (!loaded) {
    // Rendered for this request only, so a passing failure is never kept as the page.
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل أسعار العملات الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const [{ rates, meta }, converter] = loaded;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2 border-b border-line pb-6">
          <h1 className="text-title font-bold">{headline}</h1>
          {meta.rate_date && (
            <p className="mt-2 text-lead text-muted">
              أسعار يوم <time dateTime={meta.rate_date}>{formatDay(meta.rate_date)}</time>
            </p>
          )}
          <RatesStamp meta={meta} className="mt-3" />
        </header>

        <div className="mt-8 grid gap-12 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start">
          <section aria-labelledby="rates-title">
            <h2 id="rates-title" className="text-heading font-bold">
              الشراء والبيع
            </h2>
            {rates.length === 0 ? <p className="mt-4 text-lead text-muted">لا أسعار منشورة بعد.</p> : <CurrencyTable rates={rates} meta={meta} />}
            <RateNotes rates={rates} />
          </section>
          <Converter data={converter} />
        </div>
      </div>
      <JsonLd data={currenciesStructuredData(rates, meta, headline, crumbs)} />
    </>
  );
}
