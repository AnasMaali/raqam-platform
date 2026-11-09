import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { LeadCard, ListCard } from "@/components/cards/PostCard";
import styles from "@/components/home/HistoryLine.module.css";
import { Pagination, pageNumber } from "@/components/lists/ListView";
import { JsonLd } from "@/components/seo/JsonLd";
import { Pic } from "@/components/ui/Pic";
import { factsLine, historyYear } from "@/lib/facts";
import { serverEnv } from "@/lib/server/env";
import { getCharacters, getFeatured, getSection, getSectionList, type SectionInfo, type SectionList, type SectionSlug } from "@/lib/server/sections";

/*
 * The seven special sections and /news (page inventory): the section's introduction, its featured item, then its items,
 * 12 a page (/page/{n}), the newest first; the history section from the oldest, on the kashida's line. A section's
 * filters (5.9) are plain links (?season=2); a filtered page is noindex and its canonical address is the section's own
 * page. An unknown filter value, a hidden section and a page past the last are not found (src/proxy.ts gives the 404
 * before the page streams).
 */

type Slug = SectionSlug | "news";
type Query = Record<string, string | string[] | undefined>;
type Loaded = { section: SectionInfo; list: SectionList; page: number; filters: Record<string, string> };

const news: SectionInfo = { key: "news", name: "الأخبار", slug: "news", description: null, cover: null, filters: [] };

export const sectionPath = (slug: Slug, page = 1, filters: Record<string, string> = {}) => {
  const query = new URLSearchParams(filters).toString();

  return `/${slug}${page > 1 ? `/page/${page}` : ""}${query ? `?${query}` : ""}`;
};

/** The filters of the address that the section offers, with a value it knows; null for an unknown value. */
function filtersOf(section: SectionInfo, query: Query): Record<string, string> | null {
  const chosen: Record<string, string> = {};
  for (const filter of section.filters) {
    const value = query[filter.param];
    if (value === undefined) continue;
    if (typeof value !== "string" || !filter.options.some((o) => o.value === value)) return null;
    chosen[filter.param] = value;
  }

  return chosen;
}

async function load(slug: Slug, rawPage: string | undefined, query: Query): Promise<Loaded | null> {
  const page = pageNumber(rawPage);
  if (page === null) return null;
  const section = slug === "news" ? news : await getSection(slug);
  if (!section) return null;
  const filters = filtersOf(section, query);
  if (!filters) return null;
  const list = await getSectionList(slug, page, filters);
  if (page > 1 && (list.items.length === 0 || page > list.lastPage)) return null;

  return { section, list, page, filters };
}

function filterLabel(section: SectionInfo, filters: Record<string, string>) {
  return section.filters
    .filter((f) => filters[f.param])
    .map((f) => f.options.find((o) => o.value === filters[f.param])?.name)
    .filter(Boolean)
    .join("، ");
}

function titleOf(section: SectionInfo, page: number, filters: Record<string, string>) {
  const label = filterLabel(section, filters);

  return `${section.name}${label ? `: ${label}` : ""}${page > 1 ? `، صفحة ${page}` : ""}`;
}

export async function sectionMetadata(slug: Slug, rawPage: string | undefined, query: Query): Promise<Metadata> {
  const loaded = await load(slug, rawPage, query).catch(() => null);
  if (!loaded) return {};
  const { section, page, filters } = loaded;
  const filtered = Object.keys(filters).length > 0;
  const title = titleOf(section, page, filters);
  const description = section.description || (slug === "news" ? "أخبار الاقتصاد وتقاريره ومقالات الرأي والإنفوغراف في رَقَم، الأحدث أولاً." : `مواد ${section.name} في رَقَم، الأحدث أولاً.`);
  // A filtered page points to the section's own page; every other page is its own.
  const canonical = encodeURI(filtered ? sectionPath(slug) : sectionPath(slug, page));

  return {
    title,
    description,
    alternates: {
      canonical,
      ...(slug !== "news" ? { types: { "application/rss+xml": [{ url: `/feeds/sections/${slug}`, title: `رَقَم: ${section.name}` }] } } : {}),
    },
    robots: filtered ? { index: false, follow: true } : undefined,
    openGraph: { type: "website", url: canonical, title, description, siteName: "رَقَم", locale: "ar_AR", images: [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }] },
    twitter: { card: "summary_large_image", title, description },
  };
}

function structuredData(slug: Slug, section: SectionInfo, list: SectionList, page: number, crumbs: Crumb[]) {
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(sectionPath(slug, page))}`;

  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "CollectionPage",
        "@id": url,
        url,
        name: titleOf(section, page, {}),
        inLanguage: "ar",
        isPartOf: { "@id": `${base}/#website` },
        mainEntity: {
          "@type": "ItemList",
          numberOfItems: list.total,
          itemListElement: list.items.map((p, i) => ({ "@type": "ListItem", position: (page - 1) * 12 + i + 1, url: `${base}${encodeURI(p.path)}`, name: p.title })),
        },
      },
      { "@type": "BreadcrumbList", itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })) },
    ],
  };
}

const chip = "inline-flex min-h-11 items-center rounded-sm border px-3 text-small";

/** Each filter as links: «الكل» and its values. The chosen one is marked. */
function Filters({ slug, section, filters }: { slug: Slug; section: SectionInfo; filters: Record<string, string> }) {
  if (section.filters.length === 0) return null;

  return (
    <div className="mt-6 space-y-3">
      {section.filters.map((filter) => (
        <nav key={filter.param} aria-label={`تصفية حسب ${filter.name}`} className="flex flex-wrap items-center gap-x-3 gap-y-2">
          <span className="text-small font-bold text-muted">{filter.name}</span>
          <ul className="scroll-strip relative flex min-w-0 gap-2 pb-1">
            <li className="shrink-0">
              <Link
                href={sectionPath(slug, 1, Object.fromEntries(Object.entries(filters).filter(([k]) => k !== filter.param)))}
                aria-current={filters[filter.param] ? undefined : "page"}
                className={`${chip} ${filters[filter.param] ? "border-line hover:border-brand hover:text-brand" : "border-brand bg-brand font-bold text-on-brand"}`}
              >
                الكل
              </Link>
            </li>
            {filter.options.map((option) => {
              const current = filters[filter.param] === option.value;

              return (
                <li key={option.value} className="shrink-0">
                  <Link
                    href={sectionPath(slug, 1, { ...filters, [filter.param]: option.value })}
                    aria-current={current ? "page" : undefined}
                    className={`${chip} gap-1 ${current ? "border-brand bg-brand font-bold text-on-brand" : "border-line hover:border-brand hover:text-brand"}`}
                  >
                    {option.name}
                    <span className={`figures text-tiny ${current ? "" : "text-muted"}`}>({option.posts_count})</span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      ))}
    </div>
  );
}

/** The awareness characters, each a link to the cards it has said (7.10: a still picture here). */
async function Characters({ filters }: { filters: Record<string, string> }) {
  const characters = await getCharacters();
  if (characters.length === 0) return null;

  return (
    <section aria-labelledby="characters-title" className="mt-8">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4">
        <h2 id="characters-title" className="text-heading font-bold text-brand">
          الشخصيات
        </h2>
        {filters.character && (
          <Link href={sectionPath("awareness")} className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
            كل الشخصيات
          </Link>
        )}
      </div>
      <ul className="scroll-strip relative mt-4 flex min-w-0 gap-4 pb-2">
        {characters.map((c) => {
          const picture = c.poses.find((p) => p.image)?.image;
          const current = filters.character === c.slug;

          return (
            <li key={c.slug} className="w-36 shrink-0">
              <Link
                href={sectionPath("awareness", 1, { character: c.slug })}
                aria-current={current ? "page" : undefined}
                className={`group block rounded-md border p-3 text-center ${current ? "border-brand" : "border-line hover:border-brand"}`}
              >
                {picture && <Pic media={picture} sizes="7rem" ratio="1/1" className="mx-auto w-24 rounded-full" />}
                <span className="mt-2 block font-bold group-hover:text-brand">{c.name}</span>
                {c.role && <span className="mt-1 block text-tiny text-muted">{c.role}</span>}
              </Link>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

/** The history section's items on the kashida's line, from the oldest (5.4). */
function Timeline({ items }: { items: SectionList["items"] }) {
  return (
    <ol className={`${styles.line} mt-8 max-w-text`}>
      {items.map((post) => {
        const year = historyYear(post);
        const facts = factsLine(post);

        return (
          <li key={post.id} className={`${styles.node} pb-8 last:pb-0`}>
            <article className="group relative has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-4 has-[a:focus-visible]:outline-brand">
              {year !== null && <p className="figures text-subheading leading-none font-bold text-brand">{year}</p>}
              <h3 className="mt-1 text-subheading font-bold">
                <Link href={post.path} className="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand">
                  {post.title}
                </Link>
              </h3>
              {facts && <p className="mt-1 text-small text-muted">{facts}</p>}
              {post.excerpt && <p className="mt-1 text-muted">{post.excerpt}</p>}
            </article>
          </li>
        );
      })}
    </ol>
  );
}

export async function SectionView({ slug, rawPage, query }: { slug: Slug; rawPage?: string; query: Query }) {
  if (rawPage === "1") {
    // /page/1 is the section's own address (with its filters).
    const section = slug === "news" ? news : await getSection(slug);
    const filters = section ? filtersOf(section, query) : null;
    if (!section || !filters) notFound();
    permanentRedirect(encodeURI(sectionPath(slug, 1, filters)));
  }
  const loaded = await load(slug, rawPage, query);
  if (!loaded) notFound();
  const { section, list, page, filters } = loaded;
  const filtered = Object.keys(filters).length > 0;
  const featured = page === 1 && !filtered && slug !== "history" ? await getFeatured(slug) : null;
  const items = featured ? list.items.filter((p) => p.id !== featured.id) : list.items;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: section.name, path: sectionPath(slug) },
    ...(page > 1 ? [{ name: `صفحة ${page}`, path: sectionPath(slug, page) }] : []),
  ];

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2 border-b border-line pb-6">
          <h1 className="text-title font-bold">{section.name}</h1>
          {section.description && <p className="mt-3 max-w-text text-lead text-muted">{section.description}</p>}
          {(filtered || page > 1) && (
            <p className="figures mt-3 text-small text-muted">
              {[filterLabel(section, filters), page > 1 ? `صفحة ${page} من ${list.lastPage}` : null].filter(Boolean).join(" · ")}
            </p>
          )}
          {slug !== "news" && (
            <a href={`/feeds/sections/${slug}`} className="mt-3 inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
              تابع هذا القسم عبر RSS
            </a>
          )}
          {/* Awareness filters by its characters, drawn below as cards. */}
          {slug !== "awareness" && <Filters slug={slug} section={section} filters={filters} />}
        </header>

        {slug === "awareness" && <Characters filters={filters} />}

        {featured && (
          <section aria-labelledby="featured-title" className="mt-8 border-b border-line pb-8">
            <h2 id="featured-title" className="mb-4 text-small font-bold text-brand">
              مميّز
            </h2>
            <LeadCard post={featured} level={3} />
          </section>
        )}

        {items.length === 0 ? (
          <p className="mt-8 text-lead text-muted">لا مواد هنا بعد.</p>
        ) : slug === "history" ? (
          <>
            <h2 className="sr-only">الخط الزمني</h2>
            <Timeline items={items} />
          </>
        ) : (
          <>
            <h2 className="sr-only">{page > 1 ? `المواد، صفحة ${page}` : "أحدث المواد"}</h2>
            <ul className="mt-8 grid gap-x-6 gap-y-6 sm:grid-cols-2 sm:gap-y-10 lg:grid-cols-3">
              {items.map((post) => (
                <li key={post.id}>
                  <ListCard post={post} />
                </li>
              ))}
            </ul>
          </>
        )}

        <Pagination page={page} lastPage={list.lastPage} base={`/${slug}`} query={new URLSearchParams(filters).toString()} />
      </div>
      <JsonLd data={structuredData(slug, section, list, page, crumbs)} />
    </>
  );
}
