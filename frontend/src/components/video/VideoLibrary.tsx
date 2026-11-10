import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { Pagination, pageNumber } from "@/components/lists/ListView";
import { JsonLd } from "@/components/seo/JsonLd";
import { Pic } from "@/components/ui/Pic";
import { serverEnv } from "@/lib/server/env";
import { getPlaylists, getVideos } from "@/lib/server/videos";

import { VideoCard } from "./VideoCard";

/*
 * The video library (page inventory: /video, 8.10): the playlists, the sections that have videos as filters (links;
 * a filtered page is noindex and points to the library), then 12 videos a page, newest first, /video/page/{n} for the
 * others. src/proxy.ts answers 404 for a page past the last or a section without videos before the page streams.
 */

type Query = Record<string, string | string[] | undefined>;

const sectionOf = (query: Query) => {
  const raw = Array.isArray(query.section) ? query.section[0] : query.section;
  return raw && /^[a-z0-9-]{1,60}$/.test(raw) ? raw : undefined;
};

async function load(rawPage: string | undefined, query: Query) {
  const page = pageNumber(rawPage);
  if (page === null) return null;
  const section = sectionOf(query);
  const [videos, playlists] = await Promise.all([getVideos(page, section), getPlaylists()]);
  if ((page > 1 || section) && videos.data.length === 0) return null;

  return { page, section, videos, playlists };
}

const title = "مكتبة الفيديو";

export async function videoMetadata(rawPage: string | undefined, query: Query): Promise<Metadata> {
  const loaded = await load(rawPage, query).catch(() => null);
  if (!loaded) return {};
  const { page, section, playlists } = loaded;
  const named = section ? playlists.find((p) => p.section?.slug === section)?.section?.name : undefined;
  const path = page > 1 ? `/video/page/${page}` : "/video";
  const name = `${title}${named ? `: ${named}` : ""}${page > 1 ? `، صفحة ${page}` : ""}`;

  return {
    title: name,
    description: "فيديوهات رَقَم: حلقات البودكاست والطاولات المستديرة والتقارير المصوّرة، مرتّبة في قوائم تشغيل.",
    alternates: { canonical: section ? "/video" : path },
    robots: section ? { index: false, follow: true } : undefined,
    openGraph: { type: "website", url: path, title: name, siteName: "رَقَم", locale: "ar_AR", images: [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }] },
  };
}

export async function VideoLibrary({ rawPage, query }: { rawPage?: string; query: Query }) {
  const section = sectionOf(query);
  if (rawPage === "1") permanentRedirect(section ? `/video?${new URLSearchParams({ section })}` : "/video");
  const loaded = await load(rawPage, query);
  if (!loaded) notFound();
  const { page, videos, playlists } = loaded;
  const sections = [...new Map(playlists.flatMap((p) => (p.section ? [[p.section.slug, p.section]] : []))).values()];
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: title, path: "/video" },
    ...(page > 1 ? [{ name: `صفحة ${page}`, path: `/video/page/${page}` }] : []),
  ];
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${page > 1 ? `/video/page/${page}` : "/video"}`;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <h1 className="mt-2 text-title font-bold">{title}</h1>
        {page > 1 && (
          <p className="figures mt-2 text-small text-muted">
            صفحة {page} من {videos.meta.last_page}
          </p>
        )}

        {page === 1 && !section && playlists.length > 0 && (
          <section aria-labelledby="playlists" className="mt-8">
            <h2 id="playlists" className="text-heading font-bold">
              قوائم التشغيل
            </h2>
            <ul className="scroll-strip relative mt-4 flex gap-4 pb-2">
              {playlists.map((p) => (
                <li key={p.slug} className="w-56 shrink-0 sm:w-64">
                  <article className="group relative has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
                    <Pic media={p.cover} sizes="16rem" ratio="16/9" />
                    <h3 className="mt-2 font-medium">
                      <Link href={`/video/playlists/${p.slug}`} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
                        {p.name}
                      </Link>
                    </h3>
                    <p className="figures text-small text-muted">{p.videos_count} فيديو</p>
                  </article>
                </li>
              ))}
            </ul>
          </section>
        )}

        {sections.length > 0 && (
          <nav aria-label="تصفية حسب القسم" className="mt-8">
            <ul className="flex flex-wrap gap-2">
              {[{ slug: "", name: "الكل" }, ...sections].map((s) => {
                const current = (s.slug || undefined) === section;
                return (
                  <li key={s.slug || "all"}>
                    <Link
                      href={s.slug ? `/video?${new URLSearchParams({ section: s.slug })}` : "/video"}
                      aria-current={current ? "page" : undefined}
                      className={`inline-flex min-h-11 items-center rounded-sm border px-3 text-small ${current ? "border-brand bg-brand font-bold text-on-brand" : "border-line hover:border-brand hover:text-brand"}`}
                    >
                      {s.name}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </nav>
        )}

        <h2 className="sr-only">{page > 1 ? `الفيديوهات، صفحة ${page}` : "أحدث الفيديوهات"}</h2>
        <ul className="mt-8 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
          {videos.data.map((video) => (
            <li key={video.id}>
              <VideoCard video={video} />
            </li>
          ))}
        </ul>
        <Pagination page={page} lastPage={videos.meta.last_page} base="/video" query={section ? new URLSearchParams({ section }).toString() : ""} />
      </div>
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@graph": [
            {
              "@type": "CollectionPage",
              "@id": url,
              url,
              name: title,
              inLanguage: "ar",
              mainEntity: {
                "@type": "ItemList",
                numberOfItems: videos.meta.total,
                itemListElement: videos.data.map((v, i) => ({ "@type": "ListItem", position: (page - 1) * 12 + i + 1, url: `${base}${v.path}`, name: v.title })),
              },
            },
            { "@type": "BreadcrumbList", itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })) },
          ],
        }}
      />
    </>
  );
}
