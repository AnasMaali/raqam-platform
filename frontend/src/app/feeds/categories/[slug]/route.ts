import { getCategoryFeed } from "@/lib/server/lists";
import { decodedSlug, rssResponse } from "@/lib/server/rss";

/** A category's RSS feed (23.12): its 30 latest live items, sub-categories included. Unknown or hidden: 404. */
export async function GET(_request: Request, { params }: RouteContext<"/feeds/categories/[slug]">) {
  const slug = decodedSlug((await params).slug);
  const feed = slug === null ? null : await getCategoryFeed(slug);
  if (!feed || slug === null) return new Response("Not found", { status: 404 });

  return rssResponse(feed, `/feeds/categories/${slug}`, slug);
}
