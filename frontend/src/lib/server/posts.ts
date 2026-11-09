import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { PostDetail } from "@/lib/types";

import { apiGet } from "./api";
import { tags } from "./cache-tags";
import { serverEnv } from "./env";

/** The public address prefix of each kind (backend App\Content\PostPath). */
export const postPrefixes = ["news", "podcast", "success-stories", "made-in-nablus", "history", "initiatives", "round-table", "awareness"] as const;

export type PostAnswer = { status: "ok"; post: PostDetail } | { status: "moved"; path: string } | { status: "gone" };

/**
 * One post as the platform answers its address: the post, a move to its current address (an old slug), or gone
 * (unknown, unpublished or deleted). Every answer is cached under the post's own tag, so the webhook of an edit, a
 * slug change or a take-down refreshes it at once. A failure of the API itself is not cached: it throws.
 */
export async function getPost(id: number, slug: string): Promise<PostAnswer> {
  "use cache";
  cacheLife("post");
  cacheTag(`post:${id}`, tags.everything);

  const { apiBaseUrl, siteToken } = serverEnv();
  const headers = new Headers({ Accept: "application/json" });
  if (siteToken) headers.set("X-Raqam-Site-Token", siteToken);
  const response = await fetch(`${apiBaseUrl}/posts/${id}/${encodeURIComponent(slug)}`, { headers, redirect: "manual" });

  if (response.status === 200) return { status: "ok", post: ((await response.json()) as { data: PostDetail }).data };
  if (response.status === 301) {
    const path = ((await response.json().catch(() => null)) as { path?: unknown } | null)?.path;
    if (typeof path === "string" && path.startsWith("/")) return { status: "moved", path };
  }
  if (response.status === 404 || response.status === 410) return { status: "gone" };

  throw new Error(`The API answered ${response.status} for post ${id}`);
}

/** A few of the newest posts, prerendered at build time (the rest render on their first visit and are kept). */
export async function newestPostPaths(): Promise<{ section: string; id: string; slug: string }[]> {
  const body = await apiGet<{ data: { id: number; slug: string; path: string }[] }>("/posts?per_page=12");

  return body.data.map((p) => {
    const [, section] = p.path.split("/");

    return { section, id: String(p.id), slug: p.slug };
  });
}
