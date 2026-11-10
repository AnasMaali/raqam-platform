import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { ServiceDetail, ServicesMeta, ServiceSummary, SitePage } from "@/lib/types";

import { ApiError, apiGet } from "./api";
import { tags } from "./cache-tags";

/*
 * The pages from the panel (/about, the policies, any page the admin adds), the services and their contact links
 * (page inventory: Services, advertising and the platform's own pages).
 */

async function orNull<T>(load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** A published page, or null. */
export async function getPage(slug: string): Promise<SitePage | null> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.pages, tags.everything);

  return orNull(async () => (await apiGet<{ data: SitePage }>(`/pages/${encodeURIComponent(slug)}`)).data);
}

/** Every published page (slugs, titles, system keys), for the pages built ahead. */
export async function getPageList(): Promise<{ slug: string; title: string; system_key: string | null }[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.pages, tags.everything);

  return (await apiGet<{ data: { slug: string; title: string; system_key: string | null }[] }>("/pages")).data;
}

/** Seconds until the first special offer among these prices ends (an offer ends by itself), at most an hour. */
function untilAnOfferEnds(prices: ServiceSummary["price"][]): number {
  const now = Date.now();
  const ends = prices.map((p) => (p.offer?.ends_at ? Date.parse(p.offer.ends_at) : NaN)).filter((t) => Number.isFinite(t) && t > now);

  return Math.max(60, Math.min(3600, ...ends.map((t) => Math.ceil((t - now) / 1000))));
}

export type ServicesBoard = {
  page: { title: string; body_html: string; seo: SitePage["seo"] } | null;
  groups: { id: number; name: string; services: ServiceSummary[] }[];
  meta: ServicesMeta;
};

/** The services in their groups, with the page's text and whether booking online is on. */
export async function getServices(): Promise<ServicesBoard> {
  "use cache";
  cacheTag(tags.services, tags.pages, tags.everything);
  const body = await apiGet<{ data: Omit<ServicesBoard, "meta">; meta: ServicesMeta }>("/services");
  cacheLife({ stale: 300, revalidate: untilAnOfferEnds(body.data.groups.flatMap((g) => g.services.map((s) => s.price))), expire: 86400 });

  return { ...body.data, meta: body.meta };
}

/** One service, or null for an unknown or hidden one. */
export async function getService(slug: string): Promise<{ service: ServiceDetail; meta: ServicesMeta } | null> {
  "use cache";
  cacheTag(tags.services, tags.everything);
  const found = await orNull(async () => apiGet<{ data: ServiceDetail; meta: ServicesMeta }>(`/services/${encodeURIComponent(slug)}`));
  cacheLife({ stale: 300, revalidate: found ? untilAnOfferEnds([found.data.price]) : 300, expire: 86400 });

  return found ? { service: found.data, meta: found.meta } : null;
}
