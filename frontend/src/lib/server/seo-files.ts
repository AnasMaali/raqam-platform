import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { Post } from "@/lib/types";

import { ApiError, apiGet } from "./api";
import { tags } from "./cache-tags";
import { serverEnv } from "./env";

/*
 * The files search engines and answer engines read (decision 23): robots.txt, llms.txt, the sitemaps and the site's
 * RSS feed, written by the site from the platform's data. The platform lists every public address; the site leaves out
 * those it does not serve yet, so a sitemap never sends a crawler to a 404.
 */

/**
 * Addresses the platform lists whose pages the site does not have yet (a prefix each). Empty now that the video
 * pages and the characters' pages are built; a page the platform adds before the site has it goes here.
 */
export const notYetBuilt: string[] = [];

export const servedHere = (path: string) => !notYetBuilt.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));

export type Robots = { groups: { purpose: "search" | "answer" | "training"; agents: string[]; allow: boolean }[]; disallow: string[]; sitemaps: string[] };
export type Llms = { name: string; summary: string | null; groups: { title: string; links: { title: string; path: string; description: string | null }[] }[]; feed: string | null; sitemap: string | null };
export type SitemapIndex = { type: "pages" | "posts" | "news" | "videos"; path: string; updated_at: string | null }[];
export type SitemapUrl = { path: string; updated_at: string | null };
export type NewsUrl = { path: string; title: string; published_at: string; keywords: string[] };
export type VideoUrl = {
  page_path: string;
  video: { id: number; title: string; description: string | null; thumbnail_url: string | null; content_url: string | null; player_url: string | null; duration_seconds: number | null; publication_date: string | null };
};

async function cached<T>(path: string): Promise<T> {
  return (await apiGet<{ data: T }>(path)).data;
}

export async function getRobots(): Promise<Robots> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.site, tags.everything);

  return cached<Robots>("/robots");
}

export async function getLlms(): Promise<Llms> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.site, tags.menus, tags.pages, tags.everything);

  return cached<Llms>("/llms");
}

export async function getSitemapIndex(): Promise<{ data: SitemapIndex; meta: { posts_total: number; posts_per_page: number } }> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.posts, tags.pages, tags.everything);

  return apiGet("/sitemaps");
}

export async function getSitemapPages(): Promise<SitemapUrl[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.posts, tags.pages, tags.services, tags.menus, tags.everything);

  return cached<SitemapUrl[]>("/sitemaps/pages");
}

/** One page of the items' sitemap (2000 a page), or null past the last. */
export async function getSitemapPosts(page: number): Promise<SitemapUrl[] | null> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.posts, tags.everything);

  try {
    const body = await apiGet<{ data: SitemapUrl[]; meta: { last_page: number } }>(`/sitemaps/posts?page=${page}`);

    return page > body.meta.last_page ? null : body.data;
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

export async function getSitemapNews(): Promise<{ data: NewsUrl[]; meta: { publication: { name: string; language: string } } }> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return apiGet("/sitemaps/news");
}

export async function getSitemapVideos(): Promise<VideoUrl[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.everything);

  return cached<VideoUrl[]>("/sitemaps/videos");
}

export async function getSiteFeed(): Promise<{ title: string | null; path: string; description: string | null; updated_at: string | null; items: Post[] }> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return cached("/feeds/posts");
}

const xmlText = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&apos;");

/** The site's full address of a path, percent-encoded as a sitemap needs it, XML-escaped. */
export const loc = (path: string) => xmlText(path.startsWith("http") ? path : `${serverEnv().siteUrl.origin}${encodeURI(path)}`);

export const xml = xmlText;

export function xmlResponse(body: string): Response {
  return new Response(body, { headers: { "Content-Type": "application/xml; charset=utf-8", "Cache-Control": "public, max-age=3600" } });
}

export function textResponse(body: string): Response {
  return new Response(body, { headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "public, max-age=3600" } });
}
