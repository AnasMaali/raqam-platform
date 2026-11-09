/** The shapes the site reads from the platform API (docs/api/openapi.yaml), only the fields the site uses. */

type Variant = { url: string; width: number; height: number };

export type Media = {
  id: number;
  url: string;
  alt: string;
  width: number;
  height: number;
  variants: Record<string, { webp?: Variant; jpeg?: Variant }>;
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

export type Direction = "up" | "down" | "same" | null;

export type Rate = {
  code: string;
  path: string;
  name: string;
  mid: string | null;
  change_pct: string | null;
  direction: Direction;
};

export type BoardRate = Rate & { buy: string | null; sell: string | null };

export type RatesMeta = { rate_date: string | null; note: string };

export type City = {
  slug: string;
  name: string;
  path: string;
  is_default: boolean;
  now: {
    temperature: number;
    condition: { code: number; text: string; icon: string };
    is_day: boolean;
    observed_at: string;
    stale: boolean;
  } | null;
};

export type AdImage = Media & { mime_type: string };

export type AdSlot = {
  code: string;
  name: string;
  sizes: { desktop: { width: number; height: number } | null; mobile: { width: number; height: number } | null };
  lazy: boolean;
  active: boolean;
  empty: "house" | "hide";
  ads: {
    id: number;
    weight: number;
    advertiser: string;
    starts_at: string | null;
    ends_at: string | null;
    images: { desktop: AdImage | null; mobile: AdImage | null };
    click_url: string;
    rel: string;
  }[];
  house: { images: { desktop: AdImage | null; mobile: AdImage | null }; target: string } | null;
};

export type MenuItem = { label: string; href: string; external: boolean; opens_new_tab: boolean; children: MenuItem[] };

export type Menus = { header: MenuItem[]; footer_main: MenuItem[]; footer_legal: MenuItem[] };

export type Site = {
  name: string;
  logo: Media | null;
  seo: SiteSeo;
  contact: { address: string | null; email: string | null; phone: string | null; whatsapp_url: string | null };
  social: { platform: string; name: string; url: string }[];
};

export type Video = {
  id: number;
  path: string;
  title: string;
  duration_seconds: number | null;
  thumbnail_url: string | null;
  published_at: string;
};

type Named = { name: string; slug: string };

/** One block of the home page (GET /home), in the admin's order. */
export type HomeBlock =
  | { id: number; type: "hero" | "breaking" | "latest" | "awareness" | "most_read"; title: string; posts: Post[] }
  | { id: number; type: "category"; title: string; category: Named & { id: number }; posts: Post[] }
  | { id: number; type: "section"; title: string; section: Named & { key: string }; posts: Post[] }
  | { id: number; type: "videos"; title: string; videos: Video[] }
  | { id: number; type: "playlist"; title: string; playlist: Named; videos: Video[] }
  | { id: number; type: "markets" | "newsletter"; title: string; sources?: Record<string, string> }
  | { id: number; type: "ad"; title: string; placement: string; sources?: Record<string, string> };

export type SiteSeo = { title_suffix: string | null; default_description: string | null; default_image: Media | null };
