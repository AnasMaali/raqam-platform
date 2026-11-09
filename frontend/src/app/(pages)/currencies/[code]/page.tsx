import { notFound, permanentRedirect } from "next/navigation";
import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { Converter } from "@/components/markets/Converter";
import { LazyRateChart } from "@/components/markets/LazyRateChart";
import { HistoryTable, OtherCurrencies, RateFigures, RateNotes, RatesStamp, marketMetadata, rangeOf } from "@/components/markets/MarketsParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { formatDay, formatDayMonth } from "@/lib/format";
import { currencyHeadline, definiteName } from "@/lib/markets";
import { getConverter, getCurrencies, getHistory } from "@/lib/server/markets";
import { currencyStructuredData } from "@/lib/server/structured-data";

/*
 * One currency against the shekel (page inventory: /currencies/{code}), «سعر الدولار مقابل الشيكل اليوم»: buy and sell,
 * the change, the converter set to this currency, the 30-day chart and the last days. The address is the ISO code in
 * lower case; another case moves to it, and an unknown or hidden currency is a 404 (src/proxy.ts says so before the
 * page streams).
 */
export const instant = false;

export async function generateStaticParams() {
  const { rates } = await getCurrencies();

  // At least one: a route built ahead needs a real parameter (an unknown one builds as not found).
  return (rates.length ? rates.map((r) => r.code.toLowerCase()) : ["usd"]).map((code) => ({ code }));
}

type Params = PageProps<"/currencies/[code]">["params"];

async function load(params: Params) {
  const { code } = await params;
  if (!/^[a-z]{3}$/i.test(code)) return { status: "missing" as const };
  if (code !== code.toLowerCase()) return { status: "moved" as const, to: `/currencies/${code.toLowerCase()}` };

  try {
    const [{ rates, meta }, history, converter] = await Promise.all([getCurrencies(), getHistory(code), getConverter()]);
    const rate = rates.find((r) => r.code.toLowerCase() === code);
    if (!rate || !history) return { status: "missing" as const };

    return { status: "ok" as const, rate, rates, meta, history, converter };
  } catch (error) {
    console.error(`[site] currency ${code} is unavailable: ${(error as Error).message}`);

    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/currencies/[code]">) {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { rate } = loaded;
  const day = rate.rate_date ? ` ليوم ${formatDay(rate.rate_date)}` : " اليوم";

  return marketMetadata({
    title: currencyHeadline(rate.code, rate.name),
    description: `سعر ${definiteName(rate.code, rate.name)} مقابل الشيكل${day}: شراء ${rate.buy ?? "–"} وبيع ${rate.sell ?? "–"}، مع رسم آخر 30 يوماً ومحوّل العملات. أسعار استرشادية.`,
    path: rate.path,
  });
}

export default async function CurrencyPage({ params }: PageProps<"/currencies/[code]">) {
  const loaded = await load(params);
  if (loaded.status === "moved") permanentRedirect(loaded.to);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل سعر العملة الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { rate, rates, meta, history, converter } = loaded;
  const headline = currencyHeadline(rate.code, rate.name);
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "أسعار العملات", path: "/currencies" },
    { name: rate.name, path: rate.path },
  ];
  const range = rangeOf(history.points);

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2">
          <h1 className="text-title font-bold">{headline}</h1>
          <p className="mt-2 text-lead text-muted">
            {rate.name} (<span dir="ltr">{rate.code}</span>)
            {rate.rate_date && (
              <>
                {" "}
                · أسعار يوم <time dateTime={rate.rate_date}>{formatDay(rate.rate_date)}</time>
              </>
            )}
          </p>
        </header>

        <div className="mt-6 grid gap-x-12 gap-y-10 lg:grid-cols-[minmax(0,1fr)_24rem] lg:items-start">
          <section aria-label="سعر اليوم" className="lg:col-start-1 lg:row-start-1">
            <RateFigures rate={rate} />
            <RatesStamp meta={meta} className="mt-3" />
            <RateNotes rates={[rate]} />
          </section>

          {/* On phones the converter comes before the chart; from 1024 px it stands beside it. */}
          <div className="lg:col-start-2 lg:row-span-2 lg:row-start-1">
            <Converter data={converter} initialCode={rate.code} />
          </div>

          <section aria-labelledby="chart-title" className="lg:col-start-1 lg:row-start-2">
            <h2 id="chart-title" className="text-heading font-bold">
              آخر 30 يوماً
            </h2>
            <figure className="mt-3">
              <LazyRateChart points={history.points} decimals={history.decimals} />
              {range && (
                <figcaption className="figures mt-2 text-small text-muted">
                  السعر الوسطي من {formatDayMonth(range.from)} إلى {formatDayMonth(range.to)}: الأعلى <bdi dir="ltr">{range.high.mid}</bdi> ({formatDayMonth(range.high.date)}
                  )، والأدنى <bdi dir="ltr">{range.low.mid}</bdi> ({formatDayMonth(range.low.date)}).
                </figcaption>
              )}
            </figure>
          </section>

          <section aria-labelledby="days-title" className="lg:col-start-1 lg:row-start-3">
            <h2 id="days-title" className="text-heading font-bold">
              السعر في آخر الأيام
            </h2>
            <HistoryTable history={history} />
          </section>

          <div className="lg:col-start-2 lg:row-start-3">
            <OtherCurrencies rates={rates.filter((r) => r.code !== rate.code)} />
          </div>
        </div>
      </div>
      <JsonLd data={currencyStructuredData(rate, headline, crumbs)} />
    </>
  );
}
