import "server-only";

import { connection } from "next/server";

import { apiGet } from "@/lib/server/api";

export type Media = {
  id: number;
  url: string;
  alt: string;
  width: number;
  height: number;
  variants: Record<string, { webp?: { url: string; width: number; height: number }; jpeg?: { url: string; width: number; height: number } }>;
};

export type Post = {
  id: number;
  kind: { code: string; name: string };
  title: string;
  excerpt: string | null;
  path: string;
  published_at: string;
  cover: Media | null;
  category: { name: string; slug: string } | null;
  authors: { name: string }[];
  is_breaking: boolean;
  sponsor: { name: string } | null;
  facts: Record<string, unknown> | null;
};

export type Video = { id: number; path: string; title: string; duration_seconds: number | null; thumbnail_url: string | null; published_at: string };

export type Block =
  | { id: number; type: "hero" | "breaking" | "latest" | "most_read" | "awareness"; title: string; posts: Post[] }
  | { id: number; type: "category"; title: string; category: { name: string; slug: string }; posts: Post[] }
  | { id: number; type: "section"; title: string; section: { key: string; name: string; slug: string }; posts: Post[] }
  | { id: number; type: "videos"; title: string; videos: Video[] }
  | { id: number; type: "playlist"; title: string; playlist: { name: string; slug: string }; videos: Video[] }
  | { id: number; type: "markets" | "ad" | "newsletter"; title: string };

export type Rate = { code: string; path: string; name: string; mid: string | null; change_pct: string | null; direction: "up" | "down" | "same" | null };
export type BoardRate = Rate & { buy: string | null; sell: string | null };
export type City = { slug: string; name: string; path: string; is_default: boolean; now: { temperature: number; condition: { text: string } } | null };
export type AdSlot = { code: string; sizes: { desktop: { width: number; height: number }; mobile: { width: number; height: number } }; ads: { id: number; advertiser: string; images: { desktop: Media; mobile: Media | null } }[] };
export type MenuItem = { label: string; href: string; children: MenuItem[] };

export type Home = {
  blocks: Block[];
  rates: BoardRate[];
  rateDate: string | null;
  rateNote: string;
  cities: City[];
  ads: AdSlot[];
  menu: MenuItem[];
  /** The moment the page was made, for "قبل ساعتين" and today's date. */
  now: number;
};

/** Everything the home page directions draw, read from the platform API on this server only. */
export async function loadHome(): Promise<Home> {
  await connection();
  const get = <T,>(path: string) => apiGet<T>(path, { cache: "no-store" });
  const [home, board, weather, ads, menus] = await Promise.all([
    get<{ data: Block[] }>("/home"),
    get<{ data: BoardRate[]; meta: { rate_date: string | null; note: string } }>("/currencies"),
    get<{ data: City[] }>("/weather/widget"),
    get<{ data: AdSlot[] }>("/ads"),
    get<{ data: { header: MenuItem[] } }>("/menus"),
  ]);

  return {
    blocks: home.data,
    rates: board.data,
    rateDate: board.meta.rate_date,
    rateNote: board.meta.note,
    cities: weather.data,
    ads: ads.data,
    menu: menus.data.header,
    now: Date.now(),
  };
}

export function postsOf(home: Home, type: "hero" | "breaking" | "latest" | "most_read" | "awareness") {
  const block = home.blocks.find((b) => b.type === type);

  return block && "posts" in block ? block.posts : [];
}
