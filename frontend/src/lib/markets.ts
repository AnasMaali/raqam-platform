/*
 * The currency and weather pages' small rules, shared by the server and the converter in the browser: how an amount
 * typed in any digits is read, how a conversion is made and written, a currency's name as a page title says it, and
 * the wind's direction in words.
 */

const arabicIndic = /[٠-٩]/g;
const easternArabicIndic = /[۰-۹]/g;

/** The biggest amount the converter takes: anything larger is a typing slip, not a sum of money. */
export const maxAmount = 1_000_000_000_000;

export type Amount = { ok: true; value: number } | { ok: false; reason: "empty" | "invalid" | "too-large" };

/**
 * An amount as a visitor types it: 0-9, Arabic-Indic (٠-٩) or Persian (۰-۹) digits, the Arabic decimal mark (٫) or a
 * point, and thousands marks (, ٬ or spaces) anywhere between the digits. Never negative.
 */
export function readAmount(text: string): Amount {
  const plain = text
    .replace(arabicIndic, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(easternArabicIndic, (d) => String(d.charCodeAt(0) - 0x06f0))
    .replace(/٫/g, ".")
    .replace(/[,٬\s ٬]/g, "");
  if (plain === "") return { ok: false, reason: "empty" };
  if (!/^(\d+(\.\d*)?|\.\d+)$/.test(plain)) return { ok: false, reason: "invalid" };
  const value = Number(plain);
  if (!Number.isFinite(value)) return { ok: false, reason: "invalid" };
  if (value > maxAmount) return { ok: false, reason: "too-large" };

  return { ok: true, value };
}

/**
 * The platform's rules (11.7): from a currency to the shekel, the amount times the buy rate; from the shekel to a
 * currency, the amount divided by the sell rate. null when the rate is missing.
 */
export function convert(amount: number, direction: "to_base" | "from_base", rate: string | null): number | null {
  const r = rate === null ? NaN : Number(rate);
  if (!Number.isFinite(r) || r <= 0) return null;

  return direction === "to_base" ? amount * r : amount / r;
}

const money = new Intl.NumberFormat("ar-u-nu-latn", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const plainNumber = new Intl.NumberFormat("ar-u-nu-latn", { maximumFractionDigits: 4 });

/** 1,234.50: a sum of money, two decimals, with the 0-9 digits. The caller keeps it left to right. */
export const formatMoney = (value: number) => money.format(value);

/** An amount as typed, written back in 0-9 digits (up to 4 decimals). */
export const formatAmount = (value: number) => plainNumber.format(value);

// A short name where everyone says it so («سعر الدولار»); otherwise the full name with the article.
const shortNames: Record<string, string> = { USD: "الدولار" };

/** The currency as a headline says it: «الدولار», «الدينار الأردني», «اليورو», «الجنيه المصري». */
export function definiteName(code: string, name: string): string {
  if (shortNames[code]) return shortNames[code];

  return name
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => (word.startsWith("ال") ? word : `ال${word}`))
    .join(" ");
}

/** «سعر الدولار مقابل الشيكل اليوم»: the page's headline and the search phrase it answers. */
export const currencyHeadline = (code: string, name: string) => `سعر ${definiteName(code, name)} مقابل الشيكل اليوم`;

const winds = ["شمالية", "شمالية شرقية", "شرقية", "جنوبية شرقية", "جنوبية", "جنوبية غربية", "غربية", "شمالية غربية"];

/** Where the wind blows from, in eight words: 0° is a northerly wind. */
export function windFrom(degrees: number | null): string | null {
  if (degrees === null || !Number.isFinite(degrees)) return null;

  return winds[Math.round((((degrees % 360) + 360) % 360) / 45) % 8];
}

/** The change from one rate to the next, in percent; null when either is missing. */
export function changeBetween(previous: string | null, current: string | null): number | null {
  const a = previous === null ? NaN : Number(previous);
  const b = current === null ? NaN : Number(current);
  if (!Number.isFinite(a) || !Number.isFinite(b) || a === 0) return null;

  return Math.round(((b - a) / a) * 10000) / 100;
}
