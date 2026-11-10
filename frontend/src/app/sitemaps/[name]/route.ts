import { getSitemapNews, getSitemapPages, getSitemapPosts, getSitemapVideos, loc, servedHere, xml, xmlResponse } from "@/lib/server/seo-files";

/*
 * One part of the sitemap (sitemap.xml lists them): pages.xml, posts-{n}.xml, news.xml (Google News, the last 48
 * hours) and videos.xml. Only addresses the site serves; anything else is a 404.
 */

const urlset = (urls: string[], extra = "") => `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"${extra}>
${urls.join("\n")}
</urlset>
`;

const url = (path: string, updated: string | null, inner = "") => `<url><loc>${loc(path)}</loc>${updated ? `<lastmod>${updated}</lastmod>` : ""}${inner}</url>`;

const notFound = () => new Response("Not found\n", { status: 404, headers: { "Content-Type": "text/plain; charset=utf-8" } });

export async function GET(_request: Request, { params }: RouteContext<"/sitemaps/[name]">) {
  const { name } = await params;

  if (name === "pages.xml") {
    const pages = (await getSitemapPages()).filter((p) => servedHere(p.path));

    return xmlResponse(urlset(pages.map((p) => url(p.path, p.updated_at))));
  }

  const posts = /^posts-([1-9]\d{0,3})\.xml$/.exec(name);
  if (posts) {
    const items = await getSitemapPosts(Number(posts[1]));
    if (!items) return notFound();

    return xmlResponse(urlset(items.filter((p) => servedHere(p.path)).map((p) => url(p.path, p.updated_at))));
  }

  if (name === "news.xml") {
    const news = await getSitemapNews();
    const { name: publication, language } = news.meta.publication;

    return xmlResponse(
      urlset(
        news.data
          .filter((n) => servedHere(n.path))
          .map((n) =>
            url(
              n.path,
              null,
              `<news:news><news:publication><news:name>${xml(publication)}</news:name><news:language>${xml(language)}</news:language></news:publication><news:publication_date>${n.published_at}</news:publication_date><news:title>${xml(n.title)}</news:title></news:news>`,
            ),
          ),
        ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"',
      ),
    );
  }

  if (name === "videos.xml") {
    const videos = (await getSitemapVideos()).filter((v) => servedHere(v.page_path));

    return xmlResponse(
      urlset(
        videos.map(({ page_path, video }) =>
          url(
            page_path,
            null,
            `<video:video>${video.thumbnail_url ? `<video:thumbnail_loc>${xml(video.thumbnail_url)}</video:thumbnail_loc>` : ""}<video:title>${xml(video.title)}</video:title><video:description>${xml(video.description ?? video.title)}</video:description>${
              video.content_url ? `<video:content_loc>${xml(video.content_url)}</video:content_loc>` : ""
            }${video.player_url ? `<video:player_loc>${xml(video.player_url)}</video:player_loc>` : ""}${video.duration_seconds ? `<video:duration>${video.duration_seconds}</video:duration>` : ""}${
              video.publication_date ? `<video:publication_date>${video.publication_date}</video:publication_date>` : ""
            }</video:video>`,
          ),
        ),
        ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"',
      ),
    );
  }

  return notFound();
}
