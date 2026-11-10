import { getSitemapIndex, loc, servedHere, xmlResponse } from "@/lib/server/seo-files";

/*
 * The sitemap index: the pages, the items (2000 to a file), the news of the last 48 hours, the videos. Each part is
 * written by /sitemaps/{name}.xml from the platform's lists. A part whose pages the site does not serve yet is left out.
 */

export async function GET() {
  const index = await getSitemapIndex();
  const files: { name: string; updated: string | null }[] = [];
  for (const entry of index.data) {
    if (entry.type === "posts") {
      const pages = Math.max(1, Math.ceil(index.meta.posts_total / Math.max(1, index.meta.posts_per_page)));
      for (let n = 1; n <= pages; n++) files.push({ name: `posts-${n}.xml`, updated: entry.updated_at });
    } else if (entry.type !== "videos" || servedHere("/video/1")) files.push({ name: `${entry.type}.xml`, updated: entry.updated_at });
  }

  return xmlResponse(`<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${files.map((f) => `<sitemap><loc>${loc(`/sitemaps/${f.name}`)}</loc>${f.updated ? `<lastmod>${f.updated}</lastmod>` : ""}</sitemap>`).join("\n")}
</sitemapindex>
`);
}
