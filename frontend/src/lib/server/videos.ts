import "server-only";

import { cacheLife, cacheTag } from "next/cache";

import type { EditorSeo, Picture, Post, Video, VideoDetail } from "@/lib/types";

import { ApiError, apiGet } from "./api";
import { tags } from "./cache-tags";

/*
 * The video library (page inventory: Video, decision 8) and the awareness characters' pages (7.13). Videos and
 * playlists change with the posts that use them, so they share the posts' refresh.
 */

export type Playlist = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  cover: Picture | null;
  section: { id: number; name: string; slug: string } | null;
  season_number: number | null;
  videos_count: number;
  seo: EditorSeo;
};

export type CharacterDetail = {
  id: number;
  name: string;
  slug: string;
  role: string | null;
  description: string | null;
  accent_color: string | null;
  poses: { code: string; name: string; image: Picture | null }[];
  cards_count: number;
  seo: EditorSeo;
};

type Page<T> = { data: T[]; meta: { page: number; per_page: number; total: number; last_page: number } };

async function orNull<T>(load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** One page of the library (12 videos), newest first, or a section's videos. */
export async function getVideos(page: number, section?: string): Promise<Page<Video>> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);
  const query = new URLSearchParams({ page: String(page) });
  if (section) query.set("section", section);

  return apiGet<Page<Video>>(`/videos?${query}`);
}

export async function getVideo(id: number): Promise<VideoDetail | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return orNull(async () => (await apiGet<{ data: VideoDetail }>(`/videos/${id}`)).data);
}

export async function getPlaylists(): Promise<Playlist[]> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return (await apiGet<{ data: Playlist[] }>("/playlists")).data;
}

export async function getPlaylist(slug: string): Promise<(Playlist & { videos: Video[] }) | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return orNull(async () => (await apiGet<{ data: Playlist & { videos: Video[] } }>(`/playlists/${encodeURIComponent(slug)}`)).data);
}

export async function getCharacter(slug: string): Promise<{ character: CharacterDetail; cards: Page<Post> } | null> {
  "use cache";
  cacheLife("home");
  cacheTag(tags.posts, tags.everything);

  return orNull(async () => {
    const [character, cards] = await Promise.all([
      apiGet<{ data: CharacterDetail }>(`/characters/${encodeURIComponent(slug)}`),
      apiGet<Page<Post>>(`/characters/${encodeURIComponent(slug)}/cards`),
    ]);

    return { character: character.data, cards };
  });
}

export async function getCharacterList(): Promise<{ slug: string }[]> {
  "use cache";
  cacheLife("hours");
  cacheTag(tags.posts, tags.everything);

  return (await apiGet<{ data: { slug: string }[] }>("/characters")).data;
}
