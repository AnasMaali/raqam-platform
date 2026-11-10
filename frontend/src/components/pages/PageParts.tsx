import type { Metadata } from "next";
import type { ReactNode } from "react";

import { Breadcrumbs, type Crumb } from "@/components/article/ArticleParts";
import styles from "@/components/article/ArticleBody.module.css";
import { JsonLd } from "@/components/seo/JsonLd";
import { formatDate } from "@/lib/format";
import { serverEnv } from "@/lib/server/env";
import type { EditorSeo } from "@/lib/types";

/* The parts every page from the panel shares: its title, its date, its text in the reading column, its metadata. */

type PageLike = { title: string; seo: EditorSeo; updated_at?: string | null };

/** The first sentences of a text from the platform, without its tags, for a description the editor left empty. */
export function describe(html: string | null | undefined, max = 155): string | undefined {
  const text = (html ?? "")
    .replace(/<[^>]*>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/\s+/g, " ")
    .trim();
  if (!text) return undefined;
  if (text.length <= max) return text;
  const cut = text.slice(0, max);

  return `${cut.slice(0, Math.max(cut.lastIndexOf(" "), max - 20)).trim()}…`;
}

/** The editor's title and description, the canonical address, noindex when the editor asked, the sharing card. */
export function pageMetadata(page: PageLike, path: string, fallbackDescription?: string): Metadata {
  const title = page.seo.title || page.title;
  const description = page.seo.description || fallbackDescription;
  const url = encodeURI(path);
  const image = page.seo.share_image;

  return {
    title,
    description,
    alternates: { canonical: page.seo.canonical_url || url },
    robots: page.seo.noindex ? { index: false, follow: true } : undefined,
    openGraph: {
      type: "website",
      url,
      title,
      description,
      siteName: "رَقَم",
      locale: "ar_AR",
      images: image ? [{ url: image.url, width: image.width, height: image.height, alt: image.alt }] : [{ url: "/og/default.png", width: 1200, height: 630, alt: "شعار رقم" }],
    },
    twitter: { card: "summary_large_image", title, description },
  };
}

/** schema.org's own page types for «من نحن», «اتصل بنا» and the questions page; any other page is a WebPage. */
const pageTypes: Record<string, string> = { about: "AboutPage", contact: "ContactPage", faq: "FAQPage" };

/** The page and its breadcrumb as structured data; `extra` joins the page's node (a mainEntity, …). */
export function pageStructuredData(page: PageLike & { system_key?: string | null }, path: string, crumbs: Crumb[], extra: Record<string, unknown> = {}, more: Record<string, unknown>[] = []) {
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(path)}`;

  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": pageTypes[page.system_key ?? ""] ?? "WebPage",
        "@id": url,
        url,
        name: page.seo.title || page.title,
        inLanguage: "ar",
        isPartOf: { "@id": `${base}/#website` },
        breadcrumb: { "@id": `${url}#breadcrumb` },
        ...(page.updated_at ? { dateModified: page.updated_at } : {}),
        ...extra,
      },
      {
        "@type": "BreadcrumbList",
        "@id": `${url}#breadcrumb`,
        itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })),
      },
      ...more,
    ],
  };
}

/** The platform's rendered text, in the reading column, with the article's typography. */
export function PageBody({ html, className = "" }: { html: string; className?: string }) {
  if (!html.trim()) return null;

  return <div data-inline-links className={`${styles.body} max-w-text ${className}`} dangerouslySetInnerHTML={{ __html: html }} />;
}

/** A page's head: the breadcrumb, the title, and the date it was last changed. */
export function PageHeader({ title, crumbs, updatedAt, children }: { title: string; crumbs: Crumb[]; updatedAt?: string | null; children?: ReactNode }) {
  return (
    <header className="mt-2">
      <Breadcrumbs crumbs={crumbs} />
      <h1 className="mt-2 text-title font-bold text-balance">{title}</h1>
      {updatedAt && (
        <p className="mt-2 text-small text-muted">
          آخر تحديث: <time dateTime={updatedAt}>{formatDate(updatedAt)}</time>
        </p>
      )}
      {children}
    </header>
  );
}

type JsonLdProps = { page: PageLike & { system_key?: string | null }; path: string; crumbs: Crumb[]; extra?: Record<string, unknown>; more?: Record<string, unknown>[] };

export function PageJsonLd({ page, path, crumbs, extra, more }: JsonLdProps) {
  return <JsonLd data={pageStructuredData(page, path, crumbs, extra, more)} />;
}

/** What a page says when the platform did not answer (rendered for that request only, never kept as the page). */
export function PageUnavailable({ what }: { what: string }) {
  return (
    <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
      <h1 className="text-title font-bold">تعذّر تحميل {what} الآن</h1>
      <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
    </div>
  );
}

/** A page's own text from the panel when it is published, else its fixed name and nothing more. */
export const noSeo = { title: null, description: null, canonical_url: null, noindex: false, share_image: null };
