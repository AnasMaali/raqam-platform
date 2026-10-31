/** Dates and numbers, always through Intl with the 0-9 digits, in Palestine's time. Never formatted by hand. */

const zone = "Asia/Hebron";
const locale = "ar-PS-u-nu-latn";

const longDate = new Intl.DateTimeFormat(locale, { day: "numeric", month: "long", year: "numeric", timeZone: zone });
const dayMonth = new Intl.DateTimeFormat(locale, { day: "numeric", month: "long", timeZone: zone });
const clock = new Intl.DateTimeFormat(locale, { hour: "numeric", minute: "2-digit", timeZone: zone });
const twoDecimals = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** 27 تشرين الأول 2026 */
export const formatDate = (iso: string) => longDate.format(new Date(iso));

/** A rates day ("2026-10-27") as a date, read at noon so no time zone moves it to another day. */
export const formatDay = (day: string) => longDate.format(new Date(`${day}T12:00:00Z`));

/** 27 تشرين الأول، 4:53 م: when an item was published. Absolute, so a cached page never says "an hour ago" for days. */
export const formatPublished = (iso: string) => `${dayMonth.format(new Date(iso))}، ${clock.format(new Date(iso))}`;

export const formatClock = (iso: string) => clock.format(new Date(iso));

/** The minus sign, never a hyphen. */
export const minus = "−";

/** +0.45% or −0.06%: the sign first, kept left to right inside Arabic text by the caller. */
export function formatPercent(value: number) {
  const sign = value > 0 ? "+" : value < 0 ? minus : "";

  return `${sign}${twoDecimals.format(Math.abs(value))}%`;
}

/** A temperature rounded to whole degrees, with a real minus sign below zero. */
export function formatDegrees(value: number) {
  const rounded = Math.round(value);

  return `${rounded < 0 ? minus : ""}${Math.abs(rounded)}°`;
}
