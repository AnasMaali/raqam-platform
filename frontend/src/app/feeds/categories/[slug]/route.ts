import { serverEnv } from "@/lib/server/env";
import { getCategoryFeed } from "@/lib/server/lists";

/*
 * A category's RSS 2.0 feed (23.12), written from the platform's /feeds/categories/{slug}: its 30 latest live items,
 * sub-categories included. An unknown or hidden category is a 404.
 */

const xml = (text: string) => text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

export async function GET(_request: Request, { params }: RouteContext<"/feeds/categories/[slug]">) {
  const { slug } = await params;
  let decoded: string;
  try {
    decoded = decodeURIComponent(slug);
  } catch {
    return new Response("Not found", { status: 404 });
  }
  const feed = await getCategoryFeed(decoded);
  if (!feed) return new Response("Not found", { status: 404 });

  const base = serverEnv().siteUrl.origin;
  const self = `${base}${encodeURI(`/feeds/categories/${decoded}`)}`;
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
<title>${xml(`رَقَم: ${feed.title ?? decoded}`)}</title>
<link>${xml(`${base}${encodeURI(feed.path)}`)}</link>
<description>${xml(feed.description ?? `أحدث مواد ${feed.title ?? decoded} في رَقَم.`)}</description>
<language>ar</language>
${feed.updated_at ? `<lastBuildDate>${new Date(feed.updated_at).toUTCString()}</lastBuildDate>` : ""}
<atom:link href="${xml(self)}" rel="self" type="application/rss+xml"/>
${items}
</channel>
</rss>
`;

  return new Response(body, { headers: { "Content-Type": "application/rss+xml; charset=utf-8", "Cache-Control": "public, max-age=600" } });
}
