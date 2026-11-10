import { rssResponse } from "@/lib/server/rss";
import { getSiteFeed } from "@/lib/server/seo-files";

/** The whole site's RSS feed (23.12): the 30 latest live items, newest first. */
export async function GET() {
  return rssResponse(await getSiteFeed(), "/feeds/posts", "آخر المواد");
}
