import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { AdSlot, BoardRate, City, Menus, Post, Rate, RatesMeta, Site } from "@/lib/types";

import { apiGet } from "./api";

/*
 * What every page's frame reads from the platform API. Each answer is cached on this server with the lifetime the API
 * asks for (docs/api/openapi.yaml) and a tag named after the webhook type that refreshes it.
 *
 * A failed request is never cached: the cached function throws, the page shows its frame without that part, and the
 * next request tries again. While a production build runs, a failure stops the build instead, so a deploy never bakes
 * a page frame without its menus into the static pages.
 */

const building = process.env.NEXT_PHASE === "phase-production-build";

async function softly<T>(what: string, load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    if (building) {
      throw new Error(`The site needs the API while it builds (${what}): ${(error as Error).message}`);
    }
    console.error(`[site] ${what} is unavailable: ${(error as Error).message}`);

    return null;
  }
}

async function cachedMenus() {
  "use cache";
  cacheLife("hours");
  cacheTag("menus");

  return (await apiGet<{ data: Menus }>("/menus")).data;
}

async function cachedSite() {
  "use cache";
  cacheLife("hours");
  cacheTag("site");

  return (await apiGet<{ data: Site }>("/site")).data;
}

async function cachedTicker() {
  "use cache";
  cacheLife("rates");
  cacheTag("currencies");
  const body = await apiGet<{ data: Rate[]; meta: RatesMeta }>("/currencies/ticker");

  return { rates: body.data, meta: body.meta };
}

async function cachedBoard() {
  "use cache";
  cacheLife("rates");
  cacheTag("currencies");
  const body = await apiGet<{ data: BoardRate[]; meta: RatesMeta }>("/currencies");

  return { rates: body.data, meta: body.meta };
}

async function cachedWeather() {
  "use cache";
  cacheLife("weather");
  cacheTag("weather");
  const body = await apiGet<{ data: City[]; meta?: { attribution?: string } }>("/weather/widget");

  return { cities: body.data, attribution: body.meta?.attribution ?? "Weather data © OpenWeather" };
}

async function cachedBreaking() {
  "use cache";
  cacheLife("breaking");
  cacheTag("posts");

  return (await apiGet<{ data: Post[] }>("/posts?breaking=1")).data;
}

type Campaign = AdSlot["ads"][number];

/** One campaign, weighted (weight 80 shows twice as often as 40), among those whose dates still hold. */
function pickCampaign(ads: Campaign[], now: number): Campaign | null {
  const live = ads.filter((ad) => (!ad.starts_at || Date.parse(ad.starts_at) <= now) && (!ad.ends_at || Date.parse(ad.ends_at) > now));
  let roll = Math.random() * live.reduce((sum, ad) => sum + Math.max(1, ad.weight), 0);
  for (const ad of live) {
    roll -= Math.max(1, ad.weight);
    if (roll < 0) return ad;
  }

  return null;
}

/** Every slot with the one campaign it shows for this cache period (or none), picked as the API asks. */
async function cachedAds() {
  "use cache";
  cacheLife("ads");
  cacheTag("ads");
  const body = await apiGet<{ data: AdSlot[]; meta?: { label?: string } }>("/ads");
  const now = Date.now();
  const slots = body.data.map((slot) => {
    const campaign = pickCampaign(slot.ads, now);

    return { ...slot, ads: campaign ? [campaign] : [] };
  });

  return { slots, label: body.meta?.label ?? "إعلان" };
}

export const getMenus = () => softly("menus", cachedMenus);
export const getSite = () => softly("site", cachedSite);
export const getTicker = () => softly("currency strip", cachedTicker);
export const getRatesBoard = () => softly("currency board", cachedBoard);
export const getWeather = () => softly("weather", cachedWeather);
export const getBreaking = () => softly("breaking news", cachedBreaking);
export const getAds = () => softly("ads", cachedAds);
