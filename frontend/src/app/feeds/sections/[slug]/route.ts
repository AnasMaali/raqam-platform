import { decodedSlug, rssResponse } from "@/lib/server/rss";
import { getSectionFeed } from "@/lib/server/sections";

/** A special section's RSS feed (23.12): its 30 latest live items. Unknown or hidden: 404. */
export async function GET(_request: Request, { params }: RouteContext<"/feeds/sections/[slug]">) {
  const slug = decodedSlug((await params).slug);
  const feed = slug === null ? null : await getSectionFeed(slug);
  if (!feed || slug === null) return new Response("Not found", { status: 404 });

  return rssResponse(feed, `/feeds/sections/${slug}`, slug);
}
