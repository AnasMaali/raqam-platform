import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { VideoCard } from "@/components/video/VideoCard";
import { serverEnv } from "@/lib/server/env";
import { getPlaylist } from "@/lib/server/videos";

/*
 * A playlist (page inventory: /video/playlists/{slug}, 8.9): its name, its description and its videos in order. An
 * unknown playlist is a 404 before the page streams (src/proxy.ts).
 */
export const instant = false;

async function load(params: PageProps<"/video/playlists/[slug]">["params"]) {
  let slug: string;
  try {
    slug = decodeURIComponent((await params).slug);
  } catch {
    return { status: "missing" as const };
  }
  try {
    const playlist = await getPlaylist(slug);
    return playlist ? { status: "ok" as const, playlist } : { status: "missing" as const };
  } catch (error) {
    console.error(`[site] playlist ${slug} is unavailable: ${(error as Error).message}`);
    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/video/playlists/[slug]">): Promise<Metadata> {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { playlist } = loaded;
  const path = `/video/playlists/${playlist.slug}`;
  const title = playlist.seo.title || playlist.name;
  const image = playlist.seo.share_image ?? playlist.cover;

  return {
    title,
    description: playlist.seo.description || playlist.description || undefined,
    alternates: { canonical: playlist.seo.canonical_url || encodeURI(path) },
    robots: playlist.seo.noindex ? { index: false, follow: true } : undefined,
    openGraph: { type: "website", url: encodeURI(path), title, siteName: "رَقَم", locale: "ar_AR", ...(image ? { images: [{ url: image.url, width: image.width, height: image.height, alt: image.alt }] } : {}) },
  };
}

export default async function PlaylistPage({ params }: PageProps<"/video/playlists/[slug]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();
    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل قائمة التشغيل الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { playlist } = loaded;
  const path = `/video/playlists/${playlist.slug}`;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "مكتبة الفيديو", path: "/video" },
    { name: playlist.name, path },
  ];
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(path)}`;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <p className="mt-2 text-small font-bold text-brand">قائمة تشغيل{playlist.section ? ` · ${playlist.section.name}` : ""}</p>
        <h1 className="mt-1 text-title font-bold">{playlist.name}</h1>
        {playlist.description && <p className="mt-3 max-w-text text-lead text-muted">{playlist.description}</p>}
        <p className="figures mt-2 text-small text-muted">{playlist.videos.length} فيديو</p>
        <ol className="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
          {playlist.videos.map((video, i) => (
            <li key={video.id}>
              <p className="figures mb-1 text-small font-bold text-brand" aria-hidden="true">
                {i + 1}
              </p>
              <VideoCard video={video} level={2} />
            </li>
          ))}
        </ol>
      </div>
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@graph": [
            {
              "@type": "CollectionPage",
              "@id": url,
              url,
              name: playlist.name,
              inLanguage: "ar",
              mainEntity: { "@type": "ItemList", itemListOrder: "https://schema.org/ItemListOrderAscending", numberOfItems: playlist.videos.length, itemListElement: playlist.videos.map((v, i) => ({ "@type": "ListItem", position: i + 1, url: `${base}${v.path}`, name: v.title })) },
            },
            { "@type": "BreadcrumbList", itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })) },
          ],
        }}
      />
    </>
  );
}
