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
 */

const article = /^\/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)\/([1-9]\d{0,9})(?:\/([^/]+))?\/?$/;

// Pages of their own under a section's prefix (not posts): left to their routes.
const subRoutes = new Set(["characters"]);

export const config = {
  matcher: ["/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)/:id/:slug*"],
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

/** What the platform says about one address, or null when it cannot say now (no answer, 429, 5xx). */
async function ask(id: string, slug: string | undefined): Promise<Verdict | null> {
  const base = (process.env.API_BASE_URL ?? "").replace(/\/+$/, "");
  if (!base) return null;
  const headers = new Headers({ Accept: "application/json" });
  const token = process.env.API_SITE_TOKEN?.trim();
  if (token) headers.set("X-Raqam-Site-Token", token);

  // "-" is never a slug: the platform then answers with a move to the current address.
  const answer = await fetch(`${base}/posts/${id}/${slug ?? "-"}`, { method: "HEAD", headers, redirect: "manual", cache: "no-store", signal: AbortSignal.timeout(2500) }).catch(() => null);
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

export async function proxy(request: NextRequest) {
  if ((request.method !== "GET" && request.method !== "HEAD") || request.headers.has("rsc")) return NextResponse.next();
  const pathname = request.nextUrl.pathname;
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
