import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { connection } from "next/server";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { ListCard } from "@/components/cards/PostCard";
import { JsonLd } from "@/components/seo/JsonLd";
import { Pic } from "@/components/ui/Pic";
import { serverEnv } from "@/lib/server/env";
import { getCharacter, getCharacterList } from "@/lib/server/videos";

/*
 * One awareness character (page inventory: /awareness/characters/{slug}, 7.13): who it is, its poses, and everything
 * it has said, newest first (its first 12 cards here; the awareness page's character filter lists them all). The
 * character is shown still; its Rive animation comes with the characters' motion work. An unknown character is a
 * 404 before the page streams (src/proxy.ts).
 */
export const instant = false;

export async function generateStaticParams() {
  const characters = await getCharacterList();

  return (characters.length ? characters.map((c) => c.slug) : ["-"]).map((slug) => ({ slug }));
}

async function load(params: PageProps<"/awareness/characters/[slug]">["params"]) {
  let slug: string;
  try {
    slug = decodeURIComponent((await params).slug);
  } catch {
    return { status: "missing" as const };
  }
  try {
    const found = await getCharacter(slug);
    return found ? { status: "ok" as const, ...found } : { status: "missing" as const };
  } catch (error) {
    console.error(`[site] character ${slug} is unavailable: ${(error as Error).message}`);
    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/awareness/characters/[slug]">): Promise<Metadata> {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { character } = loaded;
  const path = `/awareness/characters/${character.slug}`;
  const title = character.seo.title && character.seo.title !== character.name ? character.seo.title : `${character.name}: شخصية من التوعية`;

  return {
    title,
    description: character.seo.description || character.description || character.role || undefined,
    alternates: { canonical: character.seo.canonical_url || path },
    robots: character.seo.noindex ? { index: false, follow: true } : undefined,
    openGraph: { type: "profile", url: path, title, siteName: "رَقَم", locale: "ar_AR" },
  };
}

export default async function CharacterPage({ params }: PageProps<"/awareness/characters/[slug]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();
    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الشخصية الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { character, cards } = loaded;
  const path = `/awareness/characters/${character.slug}`;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "التوعية", path: "/awareness" },
    { name: character.name, path },
  ];
  const portrait = character.poses.find((p) => p.image)?.image ?? null;
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${path}`;

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <Breadcrumbs crumbs={crumbs} />
        <header className="mt-2 grid items-center gap-6 border-b border-line pb-6 sm:grid-cols-[10rem_minmax(0,1fr)]">
          {portrait && <Pic media={portrait} sizes="10rem" ratio="1/1" priority className="w-40 rounded-md" />}
          <div>
            <p className="text-small font-bold text-brand">شخصية من التوعية</p>
            <h1 className="mt-1 text-title font-bold">{character.name}</h1>
            {character.role && <p className="mt-2 text-lead">{character.role}</p>}
            {character.description && <p className="mt-2 max-w-text text-muted">{character.description}</p>}
            <p className="figures mt-2 text-small text-muted">{cards.meta.total === 1 ? "بطاقة واحدة" : `${cards.meta.total} بطاقة`}</p>
          </div>
        </header>

        {cards.data.length === 0 ? (
          <p className="mt-8 text-lead text-muted">لم تقل هذه الشخصية شيئاً بعد.</p>
        ) : (
          <>
            <h2 className="mt-8 text-heading font-bold">بطاقات {character.name}</h2>
            <ul className="mt-6 grid gap-x-6 gap-y-6 sm:grid-cols-2 sm:gap-y-10 lg:grid-cols-3">
              {cards.data.map((post) => (
                <li key={post.id}>
                  <ListCard post={post} />
                </li>
              ))}
            </ul>
            {cards.meta.last_page > 1 && (
              <p className="mt-8">
                <Link href={`/awareness?${new URLSearchParams({ character: character.slug })}`} className="inline-flex min-h-11 items-center font-medium text-brand hover:underline">
                  كل بطاقات {character.name}
                </Link>
              </p>
            )}
          </>
        )}
      </div>
      <JsonLd
        data={{
          "@context": "https://schema.org",
          "@graph": [
            {
              "@type": "CollectionPage",
              "@id": url,
              url,
              name: character.name,
              inLanguage: "ar",
              about: { "@type": "Thing", name: character.name, ...(character.role ? { description: character.role } : {}) },
              mainEntity: { "@type": "ItemList", numberOfItems: cards.meta.total, itemListElement: cards.data.map((p, i) => ({ "@type": "ListItem", position: i + 1, url: `${base}${encodeURI(p.path)}`, name: p.title })) },
            },
            { "@type": "BreadcrumbList", itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })) },
          ],
        }}
      />
    </>
  );
}
