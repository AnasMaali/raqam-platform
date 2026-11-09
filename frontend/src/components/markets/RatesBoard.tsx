import Link from "next/link";

import { formatDay } from "@/lib/format";
import type { BoardRate, RatesMeta } from "@/lib/types";

import { Change } from "./Change";

/** How many currencies the board shows below 1024 px; from 1024 px it shows all of them. */
const onPhones = 3;

/**
 * The small currency board beside the lead story on the home page, drawn by the directory's rules: the page's own
 * background, hairline rules, a blue heading and tabular figures. Never a dark block on a light page.
 */
export function RatesBoard({ data, headingLevel = 2 }: { data: { rates: BoardRate[]; meta: RatesMeta } | null; headingLevel?: 2 | 3 }) {
  if (!data || data.rates.length === 0) return null;
  const { rates, meta } = data;
  const Heading = `h${headingLevel}` as const;

  return (
    <section aria-labelledby="rates-board-title" className="border-t-2 border-brand pt-3">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4">
        <Heading id="rates-board-title" className="text-subheading font-bold text-brand">
          أسعار العملات
        </Heading>
        {meta.rate_date && (
          <p className="text-small text-muted">
            <time dateTime={meta.rate_date}>{formatDay(meta.rate_date)}</time>
          </p>
        )}
      </div>
      <table className="mt-2 w-full text-small">
        <caption className="sr-only">
          أسعار العملات مقابل الشيكل{meta.rate_date ? `، ${formatDay(meta.rate_date)}` : ""}
        </caption>
        <thead>
          <tr className="border-b border-line text-muted">
            <th scope="col" className="py-2 text-start font-medium">
              العملة
            </th>
            <th scope="col" className="py-2 text-end font-medium">
              شراء
            </th>
            <th scope="col" className="py-2 text-end font-medium">
              بيع
            </th>
            <th scope="col" className="py-2 text-end font-medium">
              التغيّر
            </th>
          </tr>
        </thead>
        <tbody>
          {rates.map((rate, i) => (
            <tr key={rate.code} className={`border-b border-line ${i >= onPhones ? "max-lg:hidden" : ""}`}>
              <th scope="row" className="py-1 text-start font-medium">
                <Link href={rate.path} className="inline-flex min-h-11 min-w-11 items-center hover:text-brand">
                  {rate.name}
                </Link>
              </th>
              <td className="figures py-1 text-end">
                <span dir="ltr">{rate.buy ?? "–"}</span>
              </td>
              <td className="figures py-1 text-end">
                <span dir="ltr">{rate.sell ?? "–"}</span>
              </td>
              <td className="py-1 text-end">
                <Change value={rate.change_pct} className="figures" />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4">
        <p className="text-tiny text-muted">{meta.note}</p>
        <Link href="/currencies" className="inline-flex min-h-11 items-center gap-1 text-small font-medium text-brand hover:underline">
          {rates.length > onPhones ? <span className="lg:hidden">كل العملات</span> : null}
          <span className={rates.length > onPhones ? "max-lg:hidden" : undefined}>العملات والمحوّل</span>
        </Link>
      </div>
    </section>
  );
}
