import type { Metadata } from "next";
import Link from "next/link";

import { formatDateTime, formatDay, formatDayMonth } from "@/lib/format";
import { changeBetween, definiteName } from "@/lib/markets";
import type { CurrencyRow, RateHistory, RatesMeta } from "@/lib/types";

import { Change } from "./Change";

/* The parts of the currency pages: today's table, one currency's figures, its last days, and the notes under them. */

/** A markets or weather page's title, description, canonical address and sharing card. */
export function marketMetadata({ title, description, path }: { title: string; description: string; path: string }): Metadata {
  const url = encodeURI(path);

  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: { type: "website", url, title, description, siteName: "رَقَم", locale: "ar_AR", images: [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }] },
    twitter: { card: "summary_large_image", title, description },
  };
}

/**
 * «آخر تحديث»: the last time the source answered (`checked_at`), or when the bank published the rates shown when the
 * platform has not said; then the platform's note that the rates are indicative.
 */
export function RatesStamp({ meta, className = "" }: { meta: RatesMeta; className?: string }) {
  const updated = meta.checked_at ?? meta.published_at;

  return (
    <div className={`text-small ${className}`}>
      {updated && (
        <p>
          آخر تحديث: <time dateTime={updated}>{formatDateTime(updated)}</time>
        </p>
      )}
      <p className="text-muted">{meta.note}</p>
    </div>
  );
}

/** Why a row reads differently: currencies pegged to the dollar, and a rate the platform set by hand. */
export function RateNotes({ rates }: { rates: CurrencyRow[] }) {
  const pegged = rates.filter((r) => r.is_pegged);
  const manual = rates.some((r) => r.is_manual);
  if (!pegged.length && !manual) return null;

  return (
    <ul className="mt-3 flex flex-col gap-1 text-small text-muted">
      {pegged.length > 0 && (
        <li>
          {pegged.map((r) => definiteName(r.code, r.name)).join(" و")}: {pegged.length > 1 ? "محسوبان" : "محسوب"} من سعر الدولار وسعر الربط الثابت به.
        </li>
      )}
      {manual && (
        <li>
          <span aria-hidden="true">* </span>سعر حدّدته المنصة يدوياً.
        </li>
      )}
    </ul>
  );
}

/** Today's rates: buy, sell and the change for every visible currency, each linking to its own page. */
export function CurrencyTable({ rates, meta }: { rates: CurrencyRow[]; meta: RatesMeta }) {
  return (
    <table className="figures mt-3 w-full text-small">
      <caption className="sr-only">أسعار العملات مقابل الشيكل{meta.rate_date ? `، ${formatDay(meta.rate_date)}` : ""}</caption>
      <thead>
        <tr className="border-b border-line-strong text-muted">
          <th scope="col" className="py-2 text-start font-medium">
            العملة
          </th>
          <th scope="col" className="py-2 ps-3 text-end font-medium">
            شراء
          </th>
          <th scope="col" className="py-2 ps-3 text-end font-medium">
            بيع
          </th>
          <th scope="col" className="py-2 ps-3 text-end font-medium">
            التغيّر
          </th>
        </tr>
      </thead>
      <tbody>
        {rates.map((rate) => (
          <tr key={rate.code} className="border-b border-line">
            <th scope="row" className="py-1 text-start font-medium">
              <Link href={rate.path} className="inline-flex min-h-11 flex-wrap items-center gap-x-2 hover:text-brand">
                <span className="text-body">{rate.name}</span>
                <span dir="ltr" className="text-tiny text-muted">
                  {rate.code}
                </span>
                {rate.is_manual && (
                  <span className="text-muted">
                    <span aria-hidden="true">*</span>
                    <span className="sr-only">سعر حدّدته المنصة يدوياً</span>
                  </span>
                )}
              </Link>
            </th>
            <td className="py-1 ps-3 text-end text-body">
              <span dir="ltr">{rate.buy ?? "–"}</span>
            </td>
            <td className="py-1 ps-3 text-end text-body">
              <span dir="ltr">{rate.sell ?? "–"}</span>
            </td>
            <td className="py-1 ps-3 text-end">
              <Change value={rate.change_pct} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** One currency today: buy and sell large, the change since the day before, and the middle rate. */
export function RateFigures({ rate }: { rate: CurrencyRow }) {
  return (
    <dl className="figures grid grid-cols-2 gap-x-6 gap-y-4 border-y border-line py-4 sm:grid-cols-4">
      <div>
        <dt className="text-small text-muted">شراء</dt>
        <dd className="text-figure font-bold">
          <span dir="ltr">{rate.buy ?? "–"}</span>
        </dd>
      </div>
      <div>
        <dt className="text-small text-muted">بيع</dt>
        <dd className="text-figure font-bold">
          <span dir="ltr">{rate.sell ?? "–"}</span>
        </dd>
      </div>
      <div>
        <dt className="text-small text-muted">التغيّر عن اليوم السابق</dt>
        <dd className="mt-1 text-subheading font-bold">
          <Change value={rate.change_pct} />
        </dd>
      </div>
      <div>
        <dt className="text-small text-muted">السعر الوسطي</dt>
        <dd className="mt-1 text-subheading font-bold">
          <span dir="ltr">{rate.mid ?? "–"}</span>
        </dd>
      </div>
    </dl>
  );
}

/** The highest and the lowest middle rate of the period, with their days: the chart's caption, in words. */
export function rangeOf(points: RateHistory["points"]) {
  if (points.length === 0) return null;
  const sorted = [...points].sort((a, b) => Number(a.mid) - Number(b.mid));

  return { low: sorted[0], high: sorted[sorted.length - 1], from: points[0].date, to: points[points.length - 1].date };
}

/** The last days the rate was published, the newest first, each with its change from the day before it. */
export function HistoryTable({ history, days = 10 }: { history: RateHistory; days?: number }) {
  const points = history.points;
  const rows = points
    .map((p, i) => ({ ...p, change: i > 0 ? changeBetween(points[i - 1].mid, p.mid) : null }))
    .slice(-days)
    .reverse();

  return (
    <table className="figures mt-3 w-full max-w-text text-small">
      <caption className="sr-only">السعر الوسطي لـ{history.name} مقابل الشيكل في آخر الأيام</caption>
      <thead>
        <tr className="border-b border-line-strong text-muted">
          <th scope="col" className="py-2 text-start font-medium">
            اليوم
          </th>
          <th scope="col" className="py-2 text-end font-medium">
            السعر الوسطي
          </th>
          <th scope="col" className="py-2 text-end font-medium">
            التغيّر
          </th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.date} className="border-b border-line">
            <th scope="row" className="py-2.5 text-start font-normal">
              <time dateTime={row.date}>{formatDayMonth(row.date)}</time>
            </th>
            <td className="py-2.5 text-end text-body">
              <span dir="ltr">{row.mid}</span>
            </td>
            <td className="py-2.5 text-end">
              <Change value={row.change} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** The other currencies, each linking to its page with its middle rate and change. */
export function OtherCurrencies({ rates }: { rates: CurrencyRow[] }) {
  if (rates.length === 0) return null;

  return (
    <section aria-labelledby="other-currencies" className="border-t-2 border-brand pt-3">
      <h2 id="other-currencies" className="text-subheading font-bold">
        عملات أخرى
      </h2>
      <ul className="figures mt-2 text-small">
        {rates.map((rate) => (
          <li key={rate.code} className="border-b border-line">
            <Link href={rate.path} className="flex min-h-11 items-center justify-between gap-3 hover:text-brand">
              <span>{rate.name}</span>
              <span className="inline-flex items-center gap-3">
                <span dir="ltr" className="inline-block min-w-14 text-end">
                  {rate.mid ?? "–"}
                </span>
                <Change value={rate.change_pct} className="min-w-20 justify-end" />
              </span>
            </Link>
          </li>
        ))}
      </ul>
      <Link href="/currencies" className="mt-2 inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
        كل العملات والمحوّل
      </Link>
    </section>
  );
}
