import Link from "next/link";

import { formatDay } from "@/lib/format";
import type { Rate, RatesMeta } from "@/lib/types";

import { Change } from "./Change";

/**
 * The thin currency strip under the header of every page but the home page (the home page has the board). It stands
 * still and is swiped by hand; it never moves on its own. It ends with the rates' date and that they are indicative.
 */
export function RatesStrip({ data }: { data: { rates: Rate[]; meta: RatesMeta } | null }) {
  if (!data || data.rates.length === 0) return null;
  const { rates, meta } = data;

  return (
    <section aria-labelledby="rates-strip-title" className="border-b border-line">
      <div className="mx-auto flex max-w-page items-center gap-4 ps-4 sm:ps-6 lg:px-8">
        <h2 id="rates-strip-title" className="shrink-0 text-small font-bold">
          <Link href="/currencies" className="inline-flex min-h-11 items-center hover:text-brand">
            العملات
          </Link>
        </h2>
        <ul className="scroll-strip relative flex min-w-0 snap-x snap-mandatory items-center gap-6 pe-4 text-small sm:pe-6 lg:pe-0">
          {rates.map((rate) => (
            <li key={rate.code} className="shrink-0 snap-start">
              <Link href={rate.path} className="group inline-flex min-h-11 items-center gap-2">
                <span className="group-hover:text-brand">{rate.name}</span>
                <span dir="ltr" className="figures font-medium">
                  {rate.mid ?? "–"}
                </span>
                <Change value={rate.change_pct} className="figures" />
              </Link>
            </li>
          ))}
          {meta.rate_date && (
            <li className="shrink-0 text-tiny text-muted">
              <time dateTime={meta.rate_date}>{formatDay(meta.rate_date)}</time>، أسعار استرشادية
            </li>
          )}
        </ul>
      </div>
    </section>
  );
}
