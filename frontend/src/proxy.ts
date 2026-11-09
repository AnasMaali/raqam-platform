import { NextResponse, type NextRequest } from "next/server";

/*
 * An article's status before its page streams. A streamed page has already answered 200 when it finds out that the
 * post is unknown, removed or at another address (Next.js docs, loading.js: Status codes), so this asks the platform
 * first, with a HEAD request (no body), and answers:
 *   an old slug or no slug        301 to the current address (one more small request reads it)
 *   an unknown or removed post     404 (a rewritten page keeps its own status, so the platform's 410 is a 404 here)
 *   a malformed address            404
 *   a current address              the page, as usual
 * Client navigations (RSC requests) are left to the page, which handles all of these itself. When the platform does
 * not answer in time, the page decides too: this check never stops a page from showing.
 */

const article = /^\/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)\/([1-9]\d{0,9})(?:\/([^/]+))?\/?$/;

// Pages of their own under a section's prefix (not posts): left to their routes.
const subRoutes = new Set(["characters"]);

export const config = {
  matcher: ["/(news|podcast|success-stories|made-in-nablus|history|initiatives|round-table|awareness)/:id/:slug*"],
};

export async function proxy(request: NextRequest) {
  if ((request.method !== "GET" && request.method !== "HEAD") || request.headers.has("rsc")) return NextResponse.next();
  const match = article.exec(request.nextUrl.pathname);
  if (!match) return subRoutes.has(request.nextUrl.pathname.split("/")[2]) ? NextResponse.next() : notFound(request);

  const base = (process.env.API_BASE_URL ?? "").replace(/\/+$/, "");
  if (!base) return NextResponse.next();
  const headers = new Headers({ Accept: "application/json" });
  const token = process.env.API_SITE_TOKEN?.trim();
  if (token) headers.set("X-Raqam-Site-Token", token);
  // The slug as the platform knows it ("-" is never one: the platform then answers with the current address).
  const address = `${base}/posts/${match[2]}/${match[3] ?? "-"}`;

  const ask = (method: "HEAD" | "GET") => fetch(address, { method, headers, redirect: "manual", cache: "no-store", signal: AbortSignal.timeout(2500) }).catch(() => null);
  const answer = await ask("HEAD");
  if (!answer) return NextResponse.next();

  if (answer.status === 301) {
    const moved = await ask("GET");
    const path = ((await moved?.json().catch(() => null)) as { path?: unknown } | null)?.path;
    if (typeof path === "string" && path.startsWith("/") && !path.startsWith("//")) {
      const to = new URL(encodeURI(path), request.url);

      return NextResponse.redirect(to, { status: 301, headers: { "Cache-Control": "public, max-age=300" } });
    }

    return NextResponse.next();
  }
  if (answer.status === 404 || answer.status === 410) return notFound(request);

  return NextResponse.next();
}

/** The site's own not-found page, with its frame, answering 404. */
function notFound(request: NextRequest) {
  return NextResponse.rewrite(new URL("/_not-found", request.url));
}
