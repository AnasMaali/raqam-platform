import Link from "next/link";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageHeader, PageJsonLd, noSeo, pageMetadata } from "@/components/pages/PageParts";
import { getCategories, getPageList } from "@/lib/server/pages";
import { getRatesBoard, getSections, getWeather } from "@/lib/server/site-data";
import { getPlaylists } from "@/lib/server/videos";

/*
 * The sitemap for visitors (decision 28, page inventory: /sitemap): every part of the site on one page, from the
 * platform's lists (the sections, the categories with their children, the video library's playlists, the published
 * pages, the currencies, the cities), so a reader (or a screen reader) can see the whole site at once. The XML sitemap
 * for search engines is /sitemap.xml.
 * A list the platform does not answer is left out for that visit; the rest of the page still shows.
 */
export const instant = false;

const path = "/sitemap";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "خريطة الموقع", path },
];

type Entry = { name: string; path: string; children?: Entry[] };

// Pages from the panel shown in another group here.
const elsewhere = new Set(["services", "advertise"]);

async function softly<T>(load: () => Promise<T>): Promise<T | null> {
  try {
    return await load();
  } catch (error) {
    console.error(`[site] a list of the sitemap is unavailable: ${(error as Error).message}`);

    return null;
  }
}

async function groups(): Promise<{ id: string; title: string; entries: Entry[] }[]> {
  const [sections, categories, playlists, pages, board, weather] = await Promise.all([
    getSections(),
    softly(getCategories),
    softly(getPlaylists),
    softly(getPageList),
    getRatesBoard(),
    getWeather(),
  ]);

  return [
    { id: "map-sections", title: "الأخبار والأقسام", entries: [{ name: "الأخبار", path: "/news" }, ...(sections ?? []).map((s) => ({ name: s.name, path: `/${s.slug}` }))] },
    {
      id: "map-categories",
      title: "التصنيفات",
      entries: (categories ?? []).map((c) => ({ name: c.name, path: `/category/${c.slug}`, children: c.children.map((k) => ({ name: k.name, path: `/category/${k.slug}` })) })),
    },
    {
      id: "map-video",
      title: "الفيديو",
      entries: [{ name: "مكتبة الفيديو", path: "/video", children: (playlists ?? []).map((p) => ({ name: p.name, path: `/video/playlists/${p.slug}` })) }],
    },
    {
      id: "map-markets",
      title: "الأسعار والطقس",
      entries: [
        { name: "أسعار العملات", path: "/currencies", children: (board?.rates ?? []).map((r) => ({ name: r.name, path: `/currencies/${r.code.toLowerCase()}` })) },
        { name: "الطقس", path: "/weather", children: (weather?.cities ?? []).map((c) => ({ name: c.name, path: c.path })) },
      ],
    },
    { id: "map-services", title: "الخدمات والإعلان", entries: [{ name: "خدماتنا والأسعار", path: "/services" }, { name: "أعلن معنا", path: "/advertise" }] },
    { id: "map-pages", title: "عن رَقَم", entries: (pages ?? []).filter((p) => !elsewhere.has(p.slug)).map((p) => ({ name: p.title, path: `/${p.slug}` })) },
    {
      id: "map-follow",
      title: "تابِع رَقَم",
      entries: [
        { name: "النشرة البريدية", path: "/newsletter" },
        { name: "البحث", path: "/search" },
        { name: "خلاصة RSS", path: "/feeds/posts" },
      ],
    },
  ].filter((g) => g.entries.length > 0);
}

export async function generateMetadata() {
  return pageMetadata({ title: "خريطة الموقع", seo: noSeo }, path, "كل أقسام رَقَم وتصنيفاته وصفحاته في مكان واحد: الأخبار، الأقسام الخاصة، أسعار العملات، الطقس، الخدمات، وصفحات المنصة.");
}

function Links({ entries, nested = false }: { entries: Entry[]; nested?: boolean }) {
  return (
    <ul className={nested ? "ms-4 border-s border-line ps-3" : "mt-3"}>
      {entries.map((e) => (
        <li key={e.path}>
          <Link href={e.path} className={`inline-flex min-h-11 min-w-11 items-center hover:text-brand hover:underline ${nested ? "text-small" : "font-medium"}`}>
            {e.name}
          </Link>
          {e.children && e.children.length > 0 && <Links entries={e.children} nested />}
        </li>
      ))}
    </ul>
  );
}

export default async function SitemapPage() {
  const all = await groups();

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title="خريطة الموقع" crumbs={crumbs} />
        <div className="mt-8 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
          {all.map((g) => (
            <section key={g.id} aria-labelledby={g.id}>
              <h2 id={g.id} className="text-subheading font-bold text-brand">
                {g.title}
              </h2>
              <Links entries={g.entries} />
            </section>
          ))}
        </div>
      </div>
      <PageJsonLd page={{ title: "خريطة الموقع", seo: noSeo }} path={path} crumbs={crumbs} />
    </>
  );
}
