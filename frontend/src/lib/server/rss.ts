import "server-only";

import type { Post } from "@/lib/types";

import { serverEnv } from "./env";

/** A feed as the platform gives it (/feeds/…): the page it belongs to and its latest live items (at most 30). */
export type Feed = { title: string | null; path: string; description: string | null; updated_at: string | null; items: Post[] };

const xml = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

/** An RSS 2.0 answer for a feed (23.12), served at `self` (a path of this site). */
export function rssResponse(feed: Feed, self: string, fallbackTitle: string): Response {
  const base = serverEnv().siteUrl.origin;
  const title = feed.title ?? fallbackTitle;
  const items = feed.items
    .map((post) => {
      const url = `${base}${encodeURI(post.path)}`;
      const writers = post.authors.length ? post.authors.map((a) => a.name).join("، ") : "فريق رَقَم";

      return [
        "<item>",
        `<title>${xml(post.title)}</title>`,
        `<link>${xml(url)}</link>`,
        `<guid isPermaLink="true">${xml(url)}</guid>`,
        `<pubDate>${new Date(post.published_at).toUTCString()}</pubDate>`,
        `<dc:creator>${xml(writers)}</dc:creator>`,
        post.category ? `<category>${xml(post.category.name)}</category>` : "",
        post.excerpt ? `<description>${xml(post.excerpt)}</description>` : "",
        "</item>",
      ].join("");
    })
    .join("\n");

  const body = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<title>${xml(`رَقَم: ${title}`)}</title>
<link>${xml(`${base}${encodeURI(feed.path)}`)}</link>
<description>${xml(feed.description ?? `أحدث مواد ${title} في رَقَم.`)}</description>
<language>ar</language>
${feed.updated_at ? `<lastBuildDate>${new Date(feed.updated_at).toUTCString()}</lastBuildDate>` : ""}
<atom:link href="${xml(`${base}${encodeURI(self)}`)}" rel="self" type="application/rss+xml"/>
${items}
</channel>
</rss>
`;

  return new Response(body, { headers: { "Content-Type": "application/rss+xml; charset=utf-8", "Cache-Control": "public, max-age=600" } });
}

export function decodedSlug(slug: string): string | null {
  try {
    return decodeURIComponent(slug);
  } catch {
    return null;
  }
}
