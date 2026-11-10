import type { Metadata } from "next";
import { headers } from "next/headers";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import { InstantSearch } from "@/components/search/InstantSearch";
import { cleanQuery, queryString } from "@/lib/search";
import { getCategoryOptions, runSearch } from "@/lib/server/search";
import { getSections } from "@/lib/server/site-data";
import { visitorOf } from "@/lib/server/visitor";

/*
 * The search (page inventory: /search, decision 10). Dynamic and noindex (10.12): a query belongs to one visitor and
 * is never kept in a shared cache. The form is a plain GET form, so the results page works without JavaScript; with it,
 * the results follow the typing through /api/search. The server's own answer counts the search (the visitor settled
 * on it); the instant answers never do.
 */
export const instant = false;

const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "البحث", path: "/search" },
];

export async function generateMetadata({ searchParams }: PageProps<"/search">): Promise<Metadata> {
  const query = cleanQuery(await searchParams);

  return {
    title: query.q ? `نتائج البحث عن «${query.q}»` : "البحث",
    description: "ابحث في أخبار رَقَم وتقاريرها وبودكاستها وأقسامها.",
    robots: { index: false, follow: true },
    alternates: { canonical: "/search" },
  };
}

async function categoriesOrNone() {
  try {
    return await getCategoryOptions();
  } catch {
    return [];
  }
}

export default async function SearchPage({ searchParams }: PageProps<"/search">) {
  const query = cleanQuery(await searchParams);
  const [categories, sections] = await Promise.all([categoriesOrNone(), getSections()]);
  const result = query.q && query.q.length >= 2 ? await runSearch(query, visitorOf(await headers()), true) : null;

  return (
    <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
      <Breadcrumbs crumbs={crumbs} />
      <h1 className="mt-2 text-title font-bold">البحث</h1>
      <div className="mt-6 max-w-224">
        {/* A new address (a page, a suggestion) starts the form afresh with the server's answer. */}
        <InstantSearch
          key={queryString(query)}
          initialQuery={query}
          initialAnswer={result?.ok ? result.answer : null}
          initialError={result && !result.ok ? result.message : query.q && query.q.length < 2 ? "اكتب حرفين على الأقل." : null}
          categories={categories}
          sections={(sections ?? []).map((s) => ({ slug: s.slug, name: s.name }))}
        />
      </div>
    </div>
  );
}
