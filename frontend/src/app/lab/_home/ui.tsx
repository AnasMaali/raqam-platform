import { Alexandria } from "next/font/google";
import Link from "next/link";

import type { AdSlot, Media } from "./data";

export const alexandria = Alexandria({ subsets: ["arabic", "latin"], display: "swap" });

const minus = "−";
const twoDecimals = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const relative = new Intl.RelativeTimeFormat("ar-u-nu-latn", { numeric: "auto" });
const longDate = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { day: "numeric", month: "long", year: "numeric", timeZone: "Asia/Hebron" });
const dayMonth = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { day: "numeric", month: "long", timeZone: "Asia/Hebron" });
const weekday = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { weekday: "long", timeZone: "Asia/Hebron" });
const clock = new Intl.DateTimeFormat("ar-PS-u-nu-latn", { hour: "numeric", minute: "2-digit", timeZone: "Asia/Hebron" });

export const formatDate = (iso: string) => longDate.format(new Date(iso));
export const formatDayMonth = (iso: string) => dayMonth.format(new Date(iso));
export const formatWeekday = (iso: string) => weekday.format(new Date(iso));
export const formatClock = (iso: string) => clock.format(new Date(iso));

/** "قبل 3 ساعات", "أمس", or the date once it is older than a week. */
export function since(iso: string, now = Date.now()) {
  const minutes = Math.round((new Date(iso).getTime() - now) / 60000);
  if (minutes > -60) return relative.format(Math.min(minutes, -1), "minute");
  if (minutes > -60 * 24) return relative.format(Math.round(minutes / 60), "hour");
  if (minutes > -60 * 24 * 7) return relative.format(Math.round(minutes / 1440), "day");

  return dayMonth.format(new Date(iso));
}

/** Up, down or flat, drawn: never a typed glyph. */
export function Arrow({ direction, className = "" }: { direction: "up" | "down" | "same"; className?: string }) {
  if (direction === "same") {
    return (
      <svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" className={className}>
        <rect x="1" y="4" width="8" height="2" rx="1" fill="currentColor" />
      </svg>
    );
  }

  return (
    <svg viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" className={className}>
      <path d={direction === "up" ? "M5 1.5 9 8.5H1z" : "M5 8.5 1 1.5h8z"} fill="currentColor" />
    </svg>
  );
}

/** A signed change with its arrow; the number stays left to right inside the Arabic line. */
export function Change({ value, tabular = false, className = "" }: { value: number; tabular?: boolean; className?: string }) {
  const direction = value > 0 ? "up" : value < 0 ? "down" : "same";
  const sign = value > 0 ? "+" : value < 0 ? minus : "";
  const spoken = value > 0 ? "ارتفاع" : value < 0 ? "انخفاض" : "بدون تغيّر";

  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap ${className}`}>
      <Arrow direction={direction} />
      <span className="sr-only">{spoken}</span>
      <span dir="ltr" className={tabular ? "tabular-nums" : undefined}>{`${sign}${twoDecimals.format(Math.abs(value))}%`}</span>
    </span>
  );
}

/** A content picture in the sizes the platform already made, WebP first. */
export function Pic({ media, sizes, className = "", priority = false }: { media: Media | null; sizes: string; className?: string; priority?: boolean }) {
  if (!media) return null;
  const order = ["sm", "md", "lg", "xl"];
  const webp = order.map((k) => media.variants[k]?.webp).filter(Boolean).map((v) => `${v!.url} ${v!.width}w`).join(", ");
  const jpeg = order.map((k) => media.variants[k]?.jpeg).filter(Boolean).map((v) => `${v!.url} ${v!.width}w`).join(", ");
  const fallback = media.variants.md?.jpeg ?? media.variants.sm?.jpeg;

  return (
    <picture>
      {webp && <source type="image/webp" srcSet={webp} sizes={sizes} />}
      <img
        src={fallback?.url ?? media.url}
        srcSet={jpeg || undefined}
        sizes={sizes}
        alt={media.alt}
        width={media.width}
        height={media.height}
        loading={priority ? "eager" : "lazy"}
        fetchPriority={priority ? "high" : undefined}
        decoding="async"
        className={className}
      />
    </picture>
  );
}

export function Logo({ className = "h-9 w-auto" }: { className?: string }) {
  return (
    <Link href="/" aria-label="رقم، الصفحة الرئيسية" className="inline-flex shrink-0 items-center">
      {/* eslint-disable-next-line @next/next/no-img-element -- the logo file as it is */}
      <img src="/brand/logo-ar-horizontal.svg" alt="" width={372} height={135} className={`${className} dark:hidden`} />
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/brand/logo-ar-horizontal-white.svg" alt="" width={372} height={135} className={`${className} hidden dark:block`} />
    </Link>
  );
}

/** An ad slot: its size is kept even when empty, and it always says it is an ad. */
export function Ad({ slot, className = "" }: { slot: AdSlot | undefined; className?: string }) {
  if (!slot) return null;
  const ad = slot.ads[0];
  const { desktop, mobile } = slot.sizes;

  return (
    <aside aria-label="إعلان" className={className}>
      <p className="mb-1 text-xs opacity-75">إعلان</p>
      <div className="relative mx-auto w-full overflow-hidden" style={{ maxWidth: desktop.width }}>
        <div className="aspect-[var(--ad-m)] md:aspect-[var(--ad-d)]" style={{ "--ad-m": `${mobile.width}/${mobile.height}`, "--ad-d": `${desktop.width}/${desktop.height}` } as React.CSSProperties}>
          {ad && (
            <picture>
              {ad.images.mobile && <source media="(max-width: 767px)" srcSet={ad.images.mobile.url} />}
              <img src={ad.images.desktop.url} alt={`إعلان: ${ad.advertiser}`} loading="lazy" decoding="async" className="size-full object-cover" />
            </picture>
          )}
        </div>
      </div>
    </aside>
  );
}

export const weatherCredit = "Weather data © OpenWeather";
