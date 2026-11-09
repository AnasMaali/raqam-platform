import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { Picture, Post } from "@/lib/types";

import { tags } from "./cache-tags";
import { serverEnv } from "./env";

/*
 * The list pages: a category, a tag, a writer, a guest. Each has its own page on the platform (/categories/{slug} …)
 * and its items through /posts, 12 to a page (decision 4.6).
 */

export const listKinds = ["category", "tag", "author", "guest"] as const;
export type ListKind = (typeof listKinds)[number];

export const perPage = 12;

const entities: Record<ListKind, string> = { category: "categories", tag: "tags", author: "authors", guest: "guests" };

export type Seo = { title: string | null; description: string | null; canonical_url: string | null; noindex: boolean; share_image: Picture | null } | null;

export type ListEntity = {
  name: string;
  slug: string;
  description?: string | null;
  children?: { name: string; slug: string }[];
  posts_count?: number;
  job_title?: string | null;
  bio?: string | null;
  photo?: Picture | null;
  organization?: { name: string } | null;
  links?: Record<string, string>;
  seo?: Seo;
};

export type ListPage = { items: Post[]; page: number; lastPage: number; total: number };

/** The address of a list's page: the first has none of its own (/category/طاقة), the others end in /page/{n}. */
export const listPath = (kind: ListKind, slug: string, page = 1) => `/${kind}/${slug}${page > 1 ? `/page/${page}` : ""}`;

async function get<T>(path: string): Promise<{ status: number; body: T | null }> {
  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers({ Accept: "application/json" });
  if (siteToken) headers.set("X-Raqam-Site-Token", siteToken);
  const response = await fetch(`${apiBaseUrl}${path}`, { headers, redirect: "manual" });
  if (response.status === 404) return { status: 404, body: null };
  if (!response.ok) throw new Error(`The API answered ${response.status} for ${path}`);

  return { status: 200, body: (await response.json()) as T };
}

/**
 * One category, tag, writer or guest, or null when the platform does not know it (an unknown slug, a hidden category,
 * a tag no live item carries). A failure of the API itself is not cached: it throws.
 */
export async function getListEntity(kind: ListKind, slug: string): Promise<ListEntity | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.menus, tags.everything);

  return (await get<{ data: ListEntity }>(`/${entities[kind]}/${encodeURIComponent(slug)}`)).body?.data ?? null;
}

/** One page of a list's items, newest first. */
export async function getListPage(kind: ListKind, slug: string, page: number): Promise<ListPage> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  const { body } = await get<{ data: Post[]; meta: { page: number; last_page: number; total: number } }>(
    `/posts?${kind}=${encodeURIComponent(slug)}&per_page=${perPage}&page=${page}`,
  );

  return { items: body?.data ?? [], page: body?.meta.page ?? page, lastPage: body?.meta.last_page ?? 1, total: body?.meta.total ?? 0 };
}

/** Which lists to build ahead: every category, and the first few of the others (the rest render on their first visit). */
export async function listParams(kind: ListKind): Promise<{ slug: string }[]> {
  const { body } = await get<{ data: { slug: string; children?: { slug: string }[] }[] }>(`/${entities[kind]}${kind === "category" ? "" : "?per_page=6"}`);
  const all = (body?.data ?? []).flatMap((e) => [e.slug, ...(e.children ?? []).map((c) => c.slug)]);

  // At least one: a route built ahead needs a real parameter (an unknown one builds as not found).
  return (all.length ? all : ["-"]).slice(0, kind === "category" ? 50 : 6).map((slug) => ({ slug }));
}

/** The category's RSS feed (the platform's /feeds/categories/{slug}), or null when there is none. */
export async function getCategoryFeed(slug: string) {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return (
    await get<{ data: { title: string | null; path: string; description: string | null; updated_at: string | null; items: Post[] } }>(
      `/feeds/categories/${encodeURIComponent(slug)}`,
    )
  ).body?.data ?? null;
}
