import { NextResponse, type NextRequest } from "next/server";

/*
 * An article's status before its page streams. A streamed page has already answered 200 when it finds out that the
 * post is unknown, removed or at another address (Next.js docs, loading.js: Status codes), so this asks the platform
 * first, with a HEAD request (no body). The platform names the post's address in X-Raqam-Path. The check answers:
 *   an old slug, no slug, or another section's prefix   301 to the current address
 *   an unknown or removed post, a malformed address      404 (a rewritten page keeps its own status, so the
 *                                                        platform's 410 is a 404 here: decisions log, review of #60)
 *   a current address                                    the page, as usual
 *
 * Each answer is kept in this server's memory, so a widely read article costs the platform one request a minute, not
 * one per visit: a page or a move for 60 s, a missing post for 30 s, at most 5000 addresses (the oldest go first). A
 * 429 or 5xx from the platform is never kept and the page decides alone, as it does when the platform does not answer
 * in time. Client navigations (RSC requests) are left to the page, which handles all of these itself.
 *
 * The list pages (a category, a tag, a writer, a guest; 12 items a page) are checked the same way: an unknown one, a
 * tag no live item carries and a page past the last answer 404, and /page/1 moves to the list's own address. So are
 * the section pages and /news: a hidden section, an unknown filter value and a page past the last answer 404.
 */

const article = /^\/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)\/([1-9]\d{0,9})(?:\/([^/]+))?\/?$/;

// Pages of their own under a section's prefix (not posts): left to their routes.
const subRoutes = new Set(["characters"]);

const list = /^\/(category|tag|author|guest)\/([^/]+)(?:\/page\/([^/]+))?\/?$/;
const sectionList = /^\/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)(?:\/page\/([^/]+))?\/?$/;
// The filters a section page reads (5.9); anything else in the query is ignored, here as on the page.
const sectionFilters = ["season", "sector", "era", "initiative_status", "character"];
const listEntities = { category: "categories", tag: "tags", author: "authors", guest: "guests" } as const;
const perPage = 12;

export const config = {
  matcher: [
    "/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)/:id/:slug*",
    "/(category|tag|author|guest)/:slug/:rest*",
    "/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)",
  ],
};

type Verdict = { kind: "page" } | { kind: "moved"; to: string } | { kind: "missing" };

const maxEntries = 5000;
const keepFor = { page: 60_000, moved: 60_000, missing: 30_000 } as const;
const answers = new Map<string, { verdict: Verdict; until: number }>();

function recall(key: string): Verdict | null {
  const entry = answers.get(key);
  if (!entry) return null;
  if (entry.until > Date.now()) return entry.verdict;
  answers.delete(key);

  return null;
}

function remember(key: string, verdict: Verdict) {
  answers.delete(key);
  // A Map keeps its insertion order: the first key is the oldest.
  while (answers.size >= maxEntries) answers.delete(answers.keys().next().value!);
  answers.set(key, { verdict, until: Date.now() + keepFor[verdict.kind] });
}

const decoded = (path: string) => {
  try {
    return decodeURI(path);
  } catch {
    return path;
  }
};

/** One request to the platform, with the site token; null when it does not answer in time. */
function platform(path: string, method: "HEAD" | "GET") {
  const base = (process.env.API_BASE_URL ?? "").replace(/\/+$/, "");
  if (!base) return Promise.resolve(null);
  const headers = new Headers({ Accept: "application/json" });
  const token = process.env.API_SITE_TOKEN?.trim();
  if (token) headers.set("X-Raqam-Site-Token", token);

  return fetch(`${base}${path}`, { method, headers, redirect: "manual", cache: "no-store", signal: AbortSignal.timeout(2500) }).catch(() => null);
}

/** What the platform says about one address, or null when it cannot say now (no answer, 429, 5xx). */
async function ask(id: string, slug: string | undefined): Promise<Verdict | null> {
  // "-" is never a slug: the platform then answers with a move to the current address.
  const answer = await platform(`/posts/${id}/${slug ?? "-"}`, "HEAD");
  if (!answer) return null;
  if (answer.status === 404 || answer.status === 410) return { kind: "missing" };

  // The post's address (percent-encoded), on the post and on the move from an old slug. The caller compares it with
  // the address asked for.
  const path = answer.headers.get("x-raqam-path");
  const usable = !!path && path.startsWith("/") && !path.startsWith("//");
  if ((answer.status === 200 || answer.status === 301) && usable) return { kind: "moved", to: path };
  // A platform without the header: the post is there, and the page checks its prefix.
  if (answer.status === 200) return { kind: "page" };

  return null;
}

/** Whether a list and its page exist: the list itself (HEAD), then, past the first page, one item of that page. */
async function askList(kind: keyof typeof listEntities, slug: string, page: number): Promise<Verdict | null> {
  const entity = await platform(`/${listEntities[kind]}/${slug}`, "HEAD");
  if (!entity) return null;
  if (entity.status === 404) return { kind: "missing" };
  if (entity.status !== 200) return null;
  if (page === 1) return { kind: "page" };

  // The page asked for: a page past the last has no items. (The platform's pages are 12 items, as the site's.)
  const items = await platform(`/posts?${kind}=${slug}&per_page=${perPage}&page=${page}`, "GET");
  if (!items || items.status !== 200) return null;
  const body = (await items.json().catch(() => null)) as { data?: unknown[] } | null;
  if (!Array.isArray(body?.data)) return null;

  return body.data.length > 0 ? { kind: "page" } : { kind: "missing" };
}

async function checkList(request: NextRequest, pathname: string) {
  const match = list.exec(pathname);
  if (!match) return notFound(request);
  const [, kind, slug, rawPage] = match as unknown as [string, keyof typeof listEntities, string, string | undefined];
  // /page/1 is the list's own address.
  if (rawPage === "1") return NextResponse.redirect(new URL(`/${kind}/${slug}`, request.url), { status: 301 });
  if (rawPage !== undefined && !/^[1-9]\d{0,4}$/.test(rawPage)) return notFound(request);

  let verdict = recall(pathname);
  if (!verdict) {
    const answer = await askList(kind, slug, rawPage ? Number(rawPage) : 1);
    if (!answer) return NextResponse.next();
    verdict = answer;
    remember(pathname, verdict);
  }

  return verdict.kind === "missing" ? notFound(request) : NextResponse.next();
}

/**
 * Whether a section page exists: the section is visible, its filter values are ones it offers, and past the first page,
 * the page has an item (one item asked for, per_page=1).
 */
async function askSection(slug: string, page: number, filters: [string, string][]): Promise<Verdict | null> {
  if (slug !== "news") {
    const section = await platform(`/sections/${slug}`, filters.length ? "GET" : "HEAD");
    if (!section) return null;
    if (section.status === 404) return { kind: "missing" };
    if (section.status !== 200) return null;
    if (filters.length) {
      const body = (await section.json().catch(() => null)) as { data?: { filters?: { param: string; options: { value: string }[] }[] } } | null;
      const offered = body?.data?.filters;
      if (!offered) return null;
      const known = filters.every(([param, value]) => offered.some((f) => f.param === param && f.options.some((o) => o.value === value)));
      if (!known) return { kind: "missing" };
    }
  } else if (filters.length) return { kind: "missing" };
  if (page === 1) return { kind: "page" };

  const query = new URLSearchParams(slug === "news" ? { kind: "news,report,opinion,infographic" } : { section: slug });
  for (const [param, value] of filters) query.set(param, value);
  query.set("per_page", "1");
  query.set("page", String((page - 1) * perPage + 1));
  const items = await platform(`/posts?${query}`, "GET");
  if (!items || items.status !== 200) return null;
  const body = (await items.json().catch(() => null)) as { data?: unknown[] } | null;
  if (!Array.isArray(body?.data)) return null;

  return body.data.length > 0 ? { kind: "page" } : { kind: "missing" };
}

async function checkSection(request: NextRequest, match: RegExpExecArray) {
  const [, slug, rawPage] = match;
  const filters = sectionFilters.flatMap((param) => (request.nextUrl.searchParams.has(param) ? [[param, request.nextUrl.searchParams.get(param)!] as [string, string]] : []));
  const search = new URLSearchParams(filters).toString();
  // /page/1 is the section's own address, with its filters.
  if (rawPage === "1") return NextResponse.redirect(new URL(`/${slug}${search ? `?${search}` : ""}`, request.url), { status: 301 });
  if (rawPage !== undefined && !/^[1-9]\d{0,4}$/.test(rawPage)) return notFound(request);

  const key = `${request.nextUrl.pathname}?${search}`;
  let verdict = recall(key);
  if (!verdict) {
    const answer = await askSection(slug, rawPage ? Number(rawPage) : 1, filters);
    if (!answer) return NextResponse.next();
    verdict = answer;
    remember(key, verdict);
  }

  return verdict.kind === "missing" ? notFound(request) : NextResponse.next();
}

export async function proxy(request: NextRequest) {
  if ((request.method !== "GET" && request.method !== "HEAD") || request.headers.has("rsc")) return NextResponse.next();
  const pathname = request.nextUrl.pathname;
  if (/^\/(category|tag|author|guest)\//.test(pathname)) return checkList(request, pathname);
  const section = sectionList.exec(pathname);
  if (section) return checkSection(request, section);
  const match = article.exec(pathname);
  if (!match) return subRoutes.has(pathname.split("/")[2]) ? NextResponse.next() : notFound(request);

  let verdict = recall(pathname);
  if (!verdict) {
    const answer = await ask(match[2], match[3]);
    if (!answer) return NextResponse.next();
    // A post's own address is the page; any other address of it is a move.
    verdict = answer.kind === "moved" && decoded(answer.to) === decoded(pathname) ? { kind: "page" } : answer;
    remember(pathname, verdict);
  }

  if (verdict.kind === "missing") return notFound(request);
  if (verdict.kind === "moved") {
    return NextResponse.redirect(new URL(verdict.to, request.url), { status: 301, headers: { "Cache-Control": "public, max-age=300" } });
  }

  return NextResponse.next();
}

/** The site's own not-found page, with its frame, answering 404. */
function notFound(request: NextRequest) {
  return NextResponse.rewrite(new URL("/_not-found", request.url));
}
