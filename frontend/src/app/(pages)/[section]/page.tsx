import { notFound } from "next/navigation";
import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageBody, PageHeader, PageJsonLd, describe, pageMetadata } from "@/components/pages/PageParts";
import { getPageList, getPage } from "@/lib/server/pages";

/*
 * A page from the panel at the site's root (decision 28): «من نحن», the three policies, the four legal pages, and any
 * page the admin adds later. An unknown page, or a word the platform keeps for itself, is a 404 before the page
 * streams (src/proxy.ts); the services, the contact page, the team, the questions and «أعلن معنا» have routes of
 * their own. The parameter is named as the
 * article's first part, because both are dynamic parts at the same level.
 */
export const instant = false;

// The pages with routes of their own: never drawn by this template.
const ownRoutes = new Set(["services", "contact", "team", "faq", "advertise"]);

export async function generateStaticParams() {
  const pages = (await getPageList()).filter((p) => !ownRoutes.has(p.slug));

  // At least one: a route built ahead needs a real parameter (an unknown one builds as not found).
  return (pages.length ? pages.map((p) => p.slug) : ["about"]).map((section) => ({ section }));
}

type Params = PageProps<"/[section]">["params"];

function safeDecode(value: string) {
  try {
    return decodeURIComponent(value);
  } catch {
    return null;
  }
}

async function load(params: Params) {
  const slug = safeDecode((await params).section);
  if (slug === null || ownRoutes.has(slug)) return { status: "missing" as const };

  try {
    const page = await getPage(slug);

    return page ? { status: "ok" as const, page } : { status: "missing" as const };
  } catch (error) {
    console.error(`[site] page ${slug} is unavailable: ${(error as Error).message}`);

    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/[section]">) {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};

  return pageMetadata(loaded.page, `/${loaded.page.slug}`, describe(loaded.page.body_html));
}

export default async function PanelPage({ params }: PageProps<"/[section]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    // Rendered for this request only, so a passing failure is never kept as the page.
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الصفحة الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { page } = loaded;
  const path = `/${page.slug}`;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: page.title, path },
  ];

  return (
    <>
      <article className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={page.title} crumbs={crumbs} updatedAt={page.updated_at} />
        <PageBody html={page.body_html} className="mt-8" />
      </article>
      <PageJsonLd page={page} path={path} crumbs={crumbs} />
    </>
  );
}
