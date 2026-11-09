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

/** A currency's full row on /currencies: its symbol, display decimals, the day before, and how the rate was made. */
export type CurrencyRow = BoardRate & {
  symbol: string;
  decimals: number;
  previous: string | null;
  is_manual: boolean;
  is_pegged: boolean;
  rate_date: string | null;
  published_at: string | null;
};

export type RatesMeta = { rate_date: string | null; note: string; published_at?: string | null };

/** What the converter needs of each currency, and which rate each direction uses (11.7). */
export type ConverterData = {
  base: "ILS";
  currencies: { code: string; name: string; symbol: string; decimals: number; buy: string | null; sell: string | null; is_manual: boolean }[];
  rules: { to_base: { rate: "buy" | "sell"; text: string }; from_base: { rate: "buy" | "sell"; text: string } };
};

export type RateHistory = { code: string; name: string; symbol: string; decimals: number; points: { date: string; mid: string }[] };

export type WeatherCondition = { code: number; text: string; icon: string };

export type City = {
  slug: string;
  name: string;
  path: string;
  is_default: boolean;
  now: {
    temperature: number;
    condition: WeatherCondition;
    is_day: boolean;
    observed_at: string;
    stale: boolean;
  } | null;
};

/** One city's page: the reading with today's low and high, humidity and wind, and the days ahead (12.6–12.8). */
export type CityWeather = Omit<City, "now"> & {
  latitude: number;
  longitude: number;
  now:
    | (NonNullable<City["now"]> & {
        feels_like: number | null;
        today: { min: number; max: number } | null;
        humidity: number | null;
        wind_kmh: number | null;
        wind_deg: number | null;
      })
    | null;
  forecast: { date: string; min: number; max: number; condition: WeatherCondition }[];
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

/** A picture as a post's own media list gives it (gallery, share image): sizes, caption and credit. */
export type Picture = Media & { caption: string | null; credit: string | null };

export type Organization = {
  name: string;
  slug: string;
  website: string | null;
  sector: { name: string; slug: string } | null;
  city: { name: string; slug: string } | null;
  founded_year: number | null;
  logo: Picture | null;
};

export type VideoChapter = { start: number; time: string; title: string };

export type VideoDetail = Video & {
  description: string | null;
  youtube: { id: string; is_short: boolean; watch_url: string; embed_url: string } | null;
  upload: { url: string; mime_type: string } | null;
  chapters: VideoChapter[];
  structured_data: Record<string, unknown> | null;
};

export type Guest = {
  role: { code: string; name: string };
  guest: { name: string; slug: string; job_title: string | null; bio: string | null; photo: Picture | null; organization: { name: string } | null };
};

export type Character = { name: string; slug: string; accent_color: string | null };

/** What each kind adds to the shared article (the `details` of /posts/{id}). */
export type PostDetails =
  | { kind: "infographic"; download: Picture | null }
  | { kind: "episode"; season: { number: number; title: string | null } | null; episode_number: number | null }
  | { kind: "story"; organization: Organization | null; person_name: string | null; figures: { value: string; label: string }[] }
  | { kind: "product"; product_name: string | null; organization: Organization | null; contact_url: string | null; contact_phone: string | null }
  | { kind: "history"; era: { id: number; name: string } | null; period_label: string | null; year_from: number | null; year_to: number | null }
  | { kind: "initiative"; initiative_name: string | null; organizer: Organization | null; status: "ongoing" | "ended"; url: string | null }
  | { kind: "roundtable"; held_on: string | null }
  | { kind: "awareness"; type: { code: string; name: string } | null; character: Character | null; pose: { code: string; name: string; image: Picture | null } | null };

export type PostDetail = Omit<Post, "kind" | "cover" | "category" | "authors" | "sponsor"> & {
  kind: { code: string; name: string; schema_type: string };
  subtitle: string | null;
  slug: string;
  updated_at: string;
  cover: Picture | null;
  category: { id: number; name: string; slug: string } | null;
  authors: { id: number; name: string; slug: string }[];
  sponsor: (Organization & { name: string }) | null;
  body_html: string;
  tags: { name: string; slug: string }[];
  video: VideoDetail | null;
  awareness_cards: (Post & { awareness?: PostDetails })[];
  gallery: Picture[];
  guests: Guest[];
  details: PostDetails | null;
  related: Post[];
  correction: { note: string; corrected_at: string } | null;
  seo: { title: string | null; description: string | null; canonical_url: string | null; noindex: boolean; share_image: Picture | null };
};

export type Section = { key: string; name: string; slug: string };
