import type { Metadata } from "next";
import Link from "next/link";
import { notFound, permanentRedirect } from "next/navigation";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { ListCard } from "@/components/cards/PostCard";
import { JsonLd } from "@/components/seo/JsonLd";
import { Icon } from "@/components/ui/Icon";
import { Pic } from "@/components/ui/Pic";
import { getListEntity, getListPage, listPath, type ListEntity, type ListKind, type ListPage } from "@/lib/server/lists";
import { serverEnv } from "@/lib/server/env";

/*
 * One template for the list pages: a category, a tag, a writer, a guest. 12 items a page, the newest first; the first
 * page has the list's own address, the others end in /page/{n} and each is its own canonical page. An unknown list,
 * a tag no live item carries and a page past the last answer 404 (src/proxy.ts gives that status before the page
 * streams; the page itself also says not found).
 */

const kindName: Record<ListKind, string> = { category: "تصنيف", tag: "وسم", author: "كاتب", guest: "ضيف" };

const linkNames: Record<string, string> = {
  website: "الموقع",
  x: "إكس",
  twitter: "إكس",
  linkedin: "لينكدإن",
  facebook: "فيسبوك",
  instagram: "إنستغرام",
  youtube: "يوتيوب",
};

type Loaded = { entity: ListEntity; list: ListPage; page: number };

/** The page number from the address: none is 1; "1" itself, "01", "0" or a word are not addresses of a page. */
export function pageNumber(raw: string | undefined): number | null {
  if (raw === undefined) return 1;

  return /^[1-9]\d{0,4}$/.test(raw) ? Number(raw) : null;
}

function safeDecode(value: string) {
  try {
    return decodeURIComponent(value);
  } catch {
    return null;
  }
}

async function load(kind: ListKind, rawSlug: string, rawPage: string | undefined): Promise<Loaded | null> {
  const slug = safeDecode(rawSlug);
  const page = pageNumber(rawPage);
  if (slug === null || page === null) return null;
  const entity = await getListEntity(kind, slug);
  if (!entity) return null;
  const list = await getListPage(kind, slug, page);
  // Page 1 of an existing list stays (an empty category says so); a page past the last does not exist.
  if (page > 1 && (list.items.length === 0 || page > list.lastPage)) return null;

  return { entity, list, page };
}

function titleOf(kind: ListKind, entity: ListEntity, page: number) {
  const base = entity.seo?.title || (kind === "tag" ? `وسم: ${entity.name}` : entity.name);

  return page > 1 ? `${base}، صفحة ${page}` : base;
}

function descriptionOf(kind: ListKind, entity: ListEntity) {
  const fallback = kind === "tag" ? `المواد الموسومة بـ«${entity.name}» في رَقَم، الأحدث أولاً.` : `مواد ${entity.name} في رَقَم، الأحدث أولاً.`;

  return entity.seo?.description || entity.description || entity.bio || fallback;
}

export async function listMetadata(kind: ListKind, rawSlug: string, rawPage?: string): Promise<Metadata> {
  const loaded = await load(kind, rawSlug, rawPage).catch(() => null);
  if (!loaded) return {};
  const { entity, page } = loaded;
  const path = encodeURI(listPath(kind, entity.slug, page));
  const title = titleOf(kind, entity, page);
  const description = descriptionOf(kind, entity);
  const image = entity.seo?.share_image ?? entity.photo;

  return {
    title,
    description,
    alternates: {
      // Each page is its own canonical page; the editor's canonical address applies to the first.
      canonical: page === 1 && entity.seo?.canonical_url ? entity.seo.canonical_url : path,
      ...(kind === "category" ? { types: { "application/rss+xml": [{ url: encodeURI(`/feeds/categories/${entity.slug}`), title: `رَقَم: ${entity.name}` }] } } : {}),
    },
    // The editor's choice, and a category with nothing in it yet (until its first item).
    robots: entity.seo?.noindex || loaded.list.total === 0 ? { index: false, follow: true } : undefined,
    openGraph: {
      type: kind === "author" || kind === "guest" ? "profile" : "website",
      url: path,
      title,
      description,
      siteName: "رَقَم",
      locale: "ar_AR",
      images: image ? [{ url: image.url, width: image.width, height: image.height, alt: image.alt }] : [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }],
    },
    twitter: { card: image ? "summary" : "summary_large_image", title, description },
  };
}

/** The list's structured data: a CollectionPage of its items, or a ProfilePage of a Person for a writer or a guest. */
function structuredData(kind: ListKind, entity: ListEntity, list: ListPage, page: number, crumbs: Crumb[]) {
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(listPath(kind, entity.slug, page))}`;
  const items = {
    "@type": "ItemList",
    itemListOrder: "https://schema.org/ItemListOrderDescending",
    numberOfItems: list.total,
    itemListElement: list.items.map((p, i) => ({ "@type": "ListItem", position: (page - 1) * 12 + i + 1, url: `${base}${encodeURI(p.path)}`, name: p.title })),
  };
  const person =
    kind === "author" || kind === "guest"
      ? {
          "@type": "Person",
          "@id": `${base}${encodeURI(listPath(kind, entity.slug))}#person`,
          name: entity.name,
          url: `${base}${encodeURI(listPath(kind, entity.slug))}`,
          ...(entity.job_title ? { jobTitle: entity.job_title } : {}),
          ...(entity.bio ? { description: entity.bio } : {}),
          ...(entity.photo ? { image: entity.photo.url } : {}),
          ...(entity.organization ? { worksFor: { "@type": "Organization", name: entity.organization.name } } : {}),
          ...(entity.links && Object.keys(entity.links).length ? { sameAs: Object.values(entity.links) } : {}),
        }
      : null;
  const page_ = person
    ? { "@type": "ProfilePage", "@id": url, url, name: titleOf(kind, entity, page), inLanguage: "ar", mainEntity: { "@id": person["@id"] }, hasPart: items }
    : { "@type": "CollectionPage", "@id": url, url, name: titleOf(kind, entity, page), inLanguage: "ar", isPartOf: { "@id": `${base}/#website` }, mainEntity: items };
  const breadcrumb = {
    "@type": "BreadcrumbList",
    itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })),
  };

  return { "@context": "https://schema.org", "@graph": [page_, ...(person ? [person] : []), breadcrumb] };
}

/**
 * «السابق», the pages around this one, «التالي». A page's address is the list's own (`base`) for the first and
 * `base/page/{n}` for the others, with the same query (a section's filters).
 */
export function Pagination({ page, lastPage, base, query = "" }: { page: number; lastPage: number; base: string; query?: string }) {
  if (lastPage <= 1) return null;
  const href = (n: number) => `${base}${n > 1 ? `/page/${n}` : ""}${query ? `?${query}` : ""}`;
  const shown = [...new Set([1, page - 1, page, page + 1, lastPage])].filter((n) => n >= 1 && n <= lastPage).sort((a, b) => a - b);
  const link = "inline-flex min-h-11 min-w-11 items-center justify-center rounded-sm px-3 hover:bg-tint hover:text-brand";

  return (
    <nav aria-label="صفحات القائمة" className="mt-10 flex flex-wrap items-center justify-center gap-1 border-t border-line pt-6">
      {page > 1 && (
        <Link href={href(page - 1)} className={`${link} gap-1`}>
          <Icon name="end" size={16} className="rotate-180" />
          السابق
        </Link>
      )}
      <ol className="flex flex-wrap items-center gap-1">
        {shown.map((n, i) => (
          <li key={n} className="flex items-center gap-1">
            {i > 0 && n - shown[i - 1] > 1 && (
              <span aria-hidden="true" className="px-1 text-muted">
                …
              </span>
            )}
            {n === page ? (
              <span aria-current="page" className="figures inline-flex min-h-11 min-w-11 items-center justify-center rounded-sm bg-brand px-3 font-bold text-on-brand">
                <span className="sr-only">صفحة </span>
                {n}
              </span>
            ) : (
              <Link href={href(n)} className={`${link} figures`}>
                <span className="sr-only">صفحة </span>
                {n}
              </Link>
            )}
          </li>
        ))}
      </ol>
      {page < lastPage && (
        <Link href={href(page + 1)} className={`${link} gap-1`}>
          التالي
          <Icon name="end" size={16} />
        </Link>
      )}
    </nav>
  );
}

function Profile({ entity }: { entity: ListEntity }) {
  const links = Object.entries(entity.links ?? {});

  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
      {entity.photo && (
        <div className="w-24 shrink-0 sm:w-32">
          <Pic media={entity.photo} sizes="8rem" ratio="1/1" priority className="rounded-full" />
        </div>
      )}
      <div className="min-w-0">
        <h1 className="text-title font-bold">{entity.name}</h1>
        {(entity.job_title || entity.organization) && <p className="mt-1 text-lead text-muted">{[entity.job_title, entity.organization?.name].filter(Boolean).join("، ")}</p>}
        {entity.bio && <p className="mt-3 max-w-text">{entity.bio}</p>}
        {links.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-x-4">
            {links.map(([code, url]) => (
              <li key={code}>
                <a href={url} target="_blank" rel="me noopener" className="inline-flex min-h-11 items-center text-small font-medium text-brand hover:underline">
                  {linkNames[code] ?? code}
                </a>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

export async function ListView({ kind, rawSlug, rawPage }: { kind: ListKind; rawSlug: string; rawPage?: string }) {
  if (rawPage === "1") permanentRedirect(encodeURI(listPath(kind, safeDecode(rawSlug) ?? rawSlug)));
  const loaded = await load(kind, rawSlug, rawPage);
  if (!loaded) notFound();
  const { entity, list, page } = loaded;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: titleOf(kind, entity, 1), path: listPath(kind, entity.slug) },
    ...(page > 1 ? [{ name: `صفحة ${page}`, path: listPath(kind, entity.slug, page) }] : []),
  ];

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-12 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2 border-b border-line pb-6">
          {kind === "author" || kind === "guest" ? (
            <Profile entity={entity} />
          ) : (
            <>
              <p className="text-small font-bold text-brand">{kindName[kind]}</p>
              <h1 className="mt-1 text-title font-bold">{entity.name}</h1>
              {entity.description && <p className="mt-3 max-w-text text-lead text-muted">{entity.description}</p>}
            </>
          )}
          {page > 1 && (
            <p className="figures mt-3 text-small text-muted">
              صفحة {page} من {list.lastPage}
            </p>
          )}
          {entity.children && entity.children.length > 0 && (
            <nav aria-label="التصنيفات الفرعية" className="mt-4">
              <ul className="flex flex-wrap gap-2">
                {entity.children.map((c) => (
                  <li key={c.slug}>
                    <Link href={listPath("category", c.slug)} className="inline-flex min-h-11 items-center rounded-sm border border-line px-3 text-small hover:border-brand hover:text-brand">
                      {c.name}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          )}
          {kind === "category" && (
            <a href={encodeURI(`/feeds/categories/${entity.slug}`)} className="mt-3 inline-flex min-h-11 items-center gap-1 text-small font-medium text-brand hover:underline">
              تابع هذا التصنيف عبر RSS
            </a>
          )}
        </header>

        {list.items.length === 0 ? (
          <p className="mt-8 text-lead text-muted">لا مواد هنا بعد.</p>
        ) : (
          <>
            <h2 className="sr-only">{page > 1 ? `المواد، صفحة ${page}` : "المواد"}</h2>
            <ul className="mt-8 grid gap-x-6 gap-y-6 sm:grid-cols-2 sm:gap-y-10 lg:grid-cols-3">
              {list.items.map((post) => (
                <li key={post.id}>
                  <ListCard post={post} />
                </li>
              ))}
            </ul>
          </>
        )}

        <Pagination page={page} lastPage={list.lastPage} base={listPath(kind, entity.slug)} />
      </div>
      <JsonLd data={structuredData(kind, entity, list, page, crumbs)} />
    </>
  );
}
