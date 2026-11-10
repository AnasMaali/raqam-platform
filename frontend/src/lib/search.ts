import type { Post } from "@/lib/types";

/*
 * The search's shared rules, for the page (server) and the instant results (browser): the parameters it reads, the
 * kinds a visitor can filter by, and the highlighted text the platform sends (decision 10).
 */

/** The parameters the search reads; anything else in the address is ignored. */
export const searchParams = ["q", "category", "section", "kind", "from", "to", "sort", "page"] as const;
export type SearchParam = (typeof searchParams)[number];
export type SearchQuery = Partial<Record<SearchParam, string>>;

/** The kinds of the platform, by their names on the site. */
export const kinds: { code: string; name: string }[] = [
  { code: "news", name: "خبر" },
  { code: "report", name: "تقرير" },
  { code: "opinion", name: "مقال رأي" },
  { code: "infographic", name: "إنفوغراف" },
  { code: "episode", name: "حلقة بودكاست" },
  { code: "story", name: "قصة نجاح" },
  { code: "product", name: "منتج" },
  { code: "history", name: "تاريخ" },
  { code: "initiative", name: "مبادرة" },
  { code: "roundtable", name: "جلسة طاولة مستديرة" },
  { code: "awareness", name: "بطاقة توعية" },
];

const day = /^\d{4}-\d{2}-\d{2}$/;

/** A query as the platform accepts it: q of 2 to 120 letters, known values only, the dates in order, page 1 to 50. */
export function cleanQuery(raw: Record<string, string | string[] | undefined>): SearchQuery {
  const one = (key: string) => {
    const value = raw[key];
    return (Array.isArray(value) ? value[0] : value)?.trim() ?? "";
  };
  const query: SearchQuery = {};
  const q = one("q").replace(/\s+/g, " ").slice(0, 120);
  if (q) query.q = q;
  for (const key of ["category", "section"] as const) if (/^[\p{L}\p{N}-]{1,200}$/u.test(one(key))) query[key] = one(key);
  if (kinds.some((k) => k.code === one("kind"))) query.kind = one("kind");
  if (day.test(one("from"))) query.from = one("from");
  if (day.test(one("to")) && (!query.from || one("to") >= query.from)) query.to = one("to");
  if (one("sort") === "latest") query.sort = "latest";
  const page = Number(one("page"));
  if (Number.isInteger(page) && page > 1 && page <= 50) query.page = String(page);

  return query;
}

/** The query as an address's search part, in a fixed order. */
export function queryString(query: SearchQuery, extra: SearchQuery = {}): string {
  const all = { ...query, ...extra };

  return new URLSearchParams(searchParams.flatMap((key) => (all[key] ? [[key, all[key]!]] : []))).toString();
}

const entities: Record<string, string> = { "&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": '"', "&#039;": "'", "&#39;": "'" };
const escapes: Record<string, string> = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };

/**
 * The platform's highlighted text as safe HTML: every character is escaped, and only the bare <mark> and </mark> come
 * back as tags. The platform sends its text already escaped (`&lt;` for «<»), so each piece between the marks is first
 * read back to plain text (the five entities its escaping writes) and then escaped once: «<» shows as «<», and a tag
 * anywhere else (<b>, <img onerror>, <script>, a <mark> with attributes) shows as text and never runs.
 */
export function marked(html: string): string {
  return html
    .split(/(<\/?mark>)/)
    .map((part) => (part === "<mark>" || part === "</mark>" ? part : part.replace(/&(?:amp|lt|gt|quot|#0?39);/g, (e) => entities[e]).replace(/[&<>"']/g, (c) => escapes[c])))
    .join("");
}

export type SearchPerson = { id: number; name: string; slug: string; path: string; highlight: { name: string } };

export type SearchAnswer = {
  data: {
    query: string;
    engine: "meilisearch" | "database";
    posts: (Post & { highlight: { title: string; excerpt: string | null } })[];
    authors: SearchPerson[];
    guests: SearchPerson[];
    tags: SearchPerson[];
    suggestions?: string[];
    latest?: Post[];
    most_read?: Post[];
  };
  meta: { page: number; per_page: number; total: number; last_page: number };
};
