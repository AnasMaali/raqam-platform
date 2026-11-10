import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { AdvertiseBoard, Faq, ServiceDetail, ServicesMeta, ServiceSummary, SitePage, TeamMember } from "@/lib/types";

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

/** The writers on the team page, in the admin's order (no webhook names them: kept for ten minutes at most). */
export async function getTeam(): Promise<TeamMember[]> {
  "use cache";
  cacheLife({ stale: 300, revalidate: 600, expire: 86400 });
  cacheTag(tags.pages, tags.everything);

  return (await apiGet<{ data: TeamMember[] }>("/team")).data;
}

/** The FAQ page's questions, in order (they also feed the chatbot; no webhook names them: ten minutes at most). */
export async function getFaqs(): Promise<Faq[]> {
  "use cache";
  cacheLife({ stale: 300, revalidate: 600, expire: 86400 });
  cacheTag(tags.pages, tags.everything);

  return (await apiGet<{ data: Faq[] }>("/faqs")).data;
}

/** The «أعلن معنا» page: its text, the slots that are on with their sizes, the ad packages and the contact links. */
export async function getAdvertise(): Promise<AdvertiseBoard> {
  "use cache";
  cacheTag(tags.ads, tags.services, tags.pages, tags.everything);
  const body = await apiGet<{ data: AdvertiseBoard }>("/advertise");
  cacheLife({ stale: 300, revalidate: untilAnOfferEnds(body.data.packages.map((p) => p.price)), expire: 86400 });

  return body.data;
}

export type CategoryNode = { name: string; slug: string; children: { name: string; slug: string }[] };

/** The categories as a tree (one level of children), for the visitors' sitemap. */
export async function getCategories(): Promise<CategoryNode[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.menus, tags.posts, tags.everything);

  return (await apiGet<{ data: CategoryNode[] }>("/categories")).data.map((c) => ({ name: c.name, slug: c.slug, children: (c.children ?? []).map((k) => ({ name: k.name, slug: k.slug })) }));
}
