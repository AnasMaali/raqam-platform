import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import { queryString, type SearchAnswer, type SearchQuery } from "@/lib/search";

import type { Visitor } from "./api";
import { apiGet } from "./api";
import { tags } from "./cache-tags";
import { serverEnv } from "./env";

export type SearchResult = { ok: true; answer: SearchAnswer } | { ok: false; status: number; message: string };

const unavailable = "تعذّر البحث الآن. حاول بعد قليل.";

/**
 * One search on the platform, from this server with the site token and the visitor's address (its limit of 60 a
 * minute counts each visitor). `track` only when the visitor settled on the query (the results page), never while
 * typing. Never cached: a query belongs to one visitor.
 */
export async function runSearch(query: SearchQuery, visitor: Visitor | null, track: boolean): Promise<SearchResult> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers({ Accept: "application/json" });
  if (siteToken) headers.set("X-Raqam-Site-Token", siteToken);
  if (visitor) headers.set("X-Raqam-Visitor-Ip", visitor.ip);
  const path = `/search?${queryString(query)}${track ? "&track=1" : ""}`;

  try {
    const response = await fetch(`${apiBaseUrl}${path}`, { headers, cache: "no-store", redirect: "manual", signal: AbortSignal.timeout(5000) });
    if (response.ok) return { ok: true, answer: (await response.json()) as SearchAnswer };
    const body = (await response.json().catch(() => null)) as { error?: { message?: string } } | null;
    if (response.status === 429) return { ok: false, status: 429, message: "بحثت كثيراً خلال دقيقة. انتظر قليلاً ثم أعد المحاولة." };
    if (response.status === 422) return { ok: false, status: 422, message: body?.error?.message ?? "تحقّق من كلمات البحث والفلاتر." };

    return { ok: false, status: 502, message: unavailable };
  } catch (error) {
    console.error(`[site] search: ${(error as Error).message}`);

    return { ok: false, status: 502, message: unavailable };
  }
}

/** The categories (with their sub-categories) for the search's filter. */
export async function getCategoryOptions(): Promise<{ slug: string; name: string; children?: { slug: string; name: string }[] }[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.menus, tags.posts, tags.everything);

  return (await apiGet<{ data: { slug: string; name: string; children?: { slug: string; name: string }[] }[] }>("/categories")).data;
}
