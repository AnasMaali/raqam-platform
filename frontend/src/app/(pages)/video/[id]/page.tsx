import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { connection } from "next/server";

import { Breadcrumbs, Video, type Crumb } from "@/components/article/ArticleParts";
import { JsonLd } from "@/components/seo/JsonLd";
import { duration } from "@/components/video/VideoCard";
import { formatDate } from "@/lib/format";
import { serverEnv } from "@/lib/server/env";
import { getVideo } from "@/lib/server/videos";

/*
 * One video (page inventory: /video/{id}, 8.6, 8.7): the light player (the picture and a play button; YouTube's
 * privacy-enhanced player loads only on press), its chapters, its description, and the platform's VideoObject. An
 * unknown video is a 404 before the page streams (src/proxy.ts).
 */
export const instant = false;

async function load(params: PageProps<"/video/[id]">["params"]) {
  const { id } = await params;
  if (!/^[1-9]\d{0,17}$/.test(id)) return { status: "missing" as const };
  try {
    const video = await getVideo(Number(id));
    return video ? { status: "ok" as const, video } : { status: "missing" as const };
  } catch (error) {
    console.error(`[site] video ${id} is unavailable: ${(error as Error).message}`);
    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/video/[id]">): Promise<Metadata> {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { video } = loaded;

  return {
    title: video.title,
    description: video.description ?? undefined,
    alternates: { canonical: video.path },
    openGraph: {
      type: "video.other",
      url: video.path,
      title: video.title,
      description: video.description ?? undefined,
      siteName: "رَقَم",
      locale: "ar_AR",
      ...(video.thumbnail_url ? { images: [{ url: video.thumbnail_url, alt: video.title }] } : {}),
    },
    twitter: { card: "summary_large_image", title: video.title },
  };
}

export default async function OneVideoPage({ params }: PageProps<"/video/[id]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();
    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الفيديو الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { video } = loaded;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "مكتبة الفيديو", path: "/video" },
    { name: video.title, path: video.path },
  ];
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${video.path}`;
  const structured = Object.fromEntries(Object.entries(video.structured_data ?? {}).filter(([key]) => key !== "@context"));

  return (
    <>
      <article className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <h1 className="mt-2 max-w-text text-title font-bold text-balance">{video.title}</h1>
        <p className="mt-2 flex flex-wrap gap-x-3 text-small text-muted">
          <time dateTime={video.published_at}>{formatDate(video.published_at)}</time>
          {video.duration_seconds ? (
            <span className="figures">
              المدة <span dir="ltr">{duration(video.duration_seconds)}</span>
            </span>
          ) : null}
        </p>
        <div className="mt-6 max-w-224">
          <Video video={video} />
        </div>
        {video.description && <p className="mt-6 max-w-text text-lead">{video.description}</p>}
      </article>
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@graph": [
            { "@type": "WebPage", "@id": url, url, name: video.title, inLanguage: "ar", mainEntity: { "@id": `${url}#video` } },
            ...(video.structured_data ? [{ ...structured, "@id": `${url}#video` }] : []),
            { "@type": "BreadcrumbList", itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })) },
          ],
        }}
      />
    </>
  );
}
