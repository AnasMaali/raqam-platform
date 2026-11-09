import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { Picture, Post } from "@/lib/types";

import { tags } from "./cache-tags";
import { serverEnv } from "./env";

/*
 * The seven special sections and /news (page inventory): an introduction, the featured item, the newest items 12 to
 * a page, the section's filters (5.9) and its RSS feed.
 */

export const sectionSlugs = ["podcast", "success-stories", "made-in-nablus", "history", "initiatives", "round-table", "awareness"] as const;
export type SectionSlug = (typeof sectionSlugs)[number];

/** Everything under /news: news, reports, opinion and infographics (decision 4, gap 4 of the inventory). */
export const newsKinds = "news,report,opinion,infographic";

/** The only filters a section page reads from its address (5.9); anything else in the query is ignored. */
export const filterParams = ["season", "sector", "era", "initiative_status", "character"] as const;

export type SectionFilter = { param: string; name: string; options: { value: string; name: string; posts_count: number }[] };

export type SectionInfo = { key: string; name: string; slug: string; description: string | null; cover: Picture | null; filters: SectionFilter[] };

export type Character = { name: string; slug: string; role: string | null; accent_color: string | null; poses: { code: string; name: string; image: Picture | null }[]; cards_count: number };

export type SectionList = { items: Post[]; page: number; lastPage: number; total: number };

async function get<T>(path: string): Promise<T | null> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers({ Accept: "application/json" });
  if (siteToken) headers.set("X-Raqam-Site-Token", siteToken);
  const response = await fetch(`${apiBaseUrl}${path}`, { headers, redirect: "manual" });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`The API answered ${response.status} for ${path}`);

  return (await response.json()) as T;
}

/** A visible section with its filters, or null when it is hidden. */
export async function getSection(slug: SectionSlug): Promise<SectionInfo | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.menus, tags.everything);

  return (await get<{ data: SectionInfo }>(`/sections/${slug}`))?.data ?? null;
}

/** The awareness characters, each with its poses and how many cards it has said. */
export async function getCharacters(): Promise<Character[]> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return (await get<{ data: { characters: Character[] } }>("/awareness"))?.data.characters ?? [];
}

/** The query of a section's (or /news's) items: its filter, and the history timeline from the oldest. */
export function listQuery(slug: SectionSlug | "news", filters: Record<string, string>) {
  const query = new URLSearchParams(slug === "news" ? { kind: newsKinds } : { section: slug });
  for (const [key, value] of Object.entries(filters)) query.set(key, value);
  if (slug === "history") query.set("order", "timeline");

  return query;
}

/** One page of a section's items (12), filtered as asked. */
export async function getSectionList(slug: SectionSlug | "news", page: number, filters: Record<string, string>): Promise<SectionList> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  const query = listQuery(slug, filters);
  query.set("per_page", "12");
  query.set("page", String(page));
  const body = await get<{ data: Post[]; meta: { page: number; last_page: number; total: number } }>(`/posts?${query}`);

  return { items: body?.data ?? [], page, lastPage: body?.meta.last_page ?? 1, total: body?.meta.total ?? 0 };
}

/** The section's featured item (the newest the editors marked), or null. */
export async function getFeatured(slug: SectionSlug | "news"): Promise<Post | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  const query = listQuery(slug, {});
  query.delete("order");
  query.set("featured", "1");
  query.set("per_page", "1");

  return (await get<{ data: Post[] }>(`/posts?${query}`))?.data[0] ?? null;
}

/** A section's RSS feed (the platform's /feeds/sections/{slug}), or null when the section is hidden. */
export async function getSectionFeed(slug: string) {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return (
    await get<{ data: { title: string | null; path: string; description: string | null; updated_at: string | null; items: Post[] } }>(`/feeds/sections/${encodeURIComponent(slug)}`)
  )?.data ?? null;
}
