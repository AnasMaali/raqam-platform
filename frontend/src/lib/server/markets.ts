import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { City, CityWeather, ConverterData, CurrencyRow, RateHistory, RatesMeta } from "@/lib/types";

import { ApiError, apiGet } from "./api";
import { tags } from "./cache-tags";

/*
 * The currency and weather pages (page inventory: Markets and weather). Read with the lifetimes the API asks for:
 * the rates refresh when the platform's webhook says they changed, the weather every ten minutes.
 */

/** null for a 404 (an unknown or hidden currency or city); any other failure throws and nothing is kept. */
async function orNull<T>(load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** The board: every visible currency with buy, sell and the change, in the admin's order. */
export async function getCurrencies(): Promise<{ rates: CurrencyRow[]; meta: RatesMeta }> {
  "use cache";
  cacheLife("rates");
  cacheTag(tags.currencies, tags.everything);
  const body = await apiGet<{ data: CurrencyRow[]; meta: RatesMeta }>("/currencies");

  return { rates: body.data, meta: body.meta };
}

export async function getConverter(): Promise<ConverterData> {
  "use cache";
  cacheLife("rates");
  cacheTag(tags.currencies, tags.everything);

  return (await apiGet<{ data: ConverterData }>("/currencies/converter")).data;
}

/** A currency's middle rate on each publishing day of the last 30 days, or null for an unknown or hidden currency. */
export async function getHistory(code: string): Promise<RateHistory | null> {
  "use cache";
  cacheLife("rates");
  cacheTag(tags.currencies, tags.everything);

  return orNull(async () => (await apiGet<{ data: RateHistory }>(`/currencies/${encodeURIComponent(code)}/history?days=30`)).data);
}

/** Every city with its reading, the default city first in the admin's order. */
export async function getCityReadings(): Promise<{ cities: City[]; attribution: string }> {
  "use cache";
  cacheLife("weather");
  cacheTag(tags.weather, tags.everything);
  const body = await apiGet<{ data: City[]; meta?: { attribution?: string } }>("/weather/widget");

  return { cities: body.data, attribution: body.meta?.attribution ?? "Weather data © OpenWeather" };
}

/** The visible cities (names and addresses only), for the pages built ahead. */
export async function getCityList(): Promise<{ slug: string; name: string; path: string; is_default: boolean }[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.weather, tags.everything);

  return (await apiGet<{ data: { slug: string; name: string; path: string; is_default: boolean }[] }>("/weather/cities")).data;
}

/** One city's page, or null for an unknown or hidden city. */
export async function getCityWeather(slug: string): Promise<{ city: CityWeather; attribution: string } | null> {
  "use cache";
  cacheLife("weather");
  cacheTag(tags.weather, tags.everything);

  return orNull(async () => {
    const body = await apiGet<{ data: CityWeather; meta?: { attribution?: string } }>(`/weather/${encodeURIComponent(slug)}`);

    return { city: body.data, attribution: body.meta?.attribution ?? "Weather data © OpenWeather" };
  });
}
