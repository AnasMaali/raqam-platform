import "server-only";

import type { PostDetail, Site } from "@/lib/types";

import { serverEnv } from "./env";

/**
 * The site as a news organisation and as a web site with its search (schema.org), for the home page. Only what the
 * platform knows is stated: no invented founding date, address or contact.
 */
export function homeStructuredData(site: Site | null) {
  const base = serverEnv().siteUrl.origin;
  const name = site?.name ?? "رَقَم";
  const logo = site?.logo?.url ?? `${base}/brand/logo-512.png`;
  const contact = site?.contact;

  const organization: Record<string, unknown> = {
    "@type": "NewsMediaOrganization",
    "@id": `${base}/#organization`,
    name,
    url: `${base}/`,
    logo: { "@type": "ImageObject", url: logo },
    ...(site?.social.length ? { sameAs: site.social.map((s) => s.url) } : {}),
    ...(contact?.email ? { email: contact.email } : {}),
    ...(contact?.phone ? { telephone: contact.phone } : {}),
    ...(contact?.address ? { address: contact.address } : {}),
  };

  const website = {
    "@type": "WebSite",
    "@id": `${base}/#website`,
    name,
    url: `${base}/`,
    inLanguage: "ar",
    publisher: { "@id": `${base}/#organization` },
    potentialAction: {
      "@type": "SearchAction",
      target: { "@type": "EntryPoint", urlTemplate: `${base}/search?q={search_term_string}` },
      "query-input": "required name=search_term_string",
    },
  };

  return { "@context": "https://schema.org", "@graph": [organization, website] };
}

type Crumb = { name: string; path: string };

/**
 * An article as schema.org reads it: the type the platform names for its kind (NewsArticle, ReportageNewsArticle,
 * OpinionNewsArticle, Article), its breadcrumb, and its video when it has one (an episode's chapters come as Clips).
 */
export function articleStructuredData(post: PostDetail, crumbs: Crumb[], site: Site | null) {
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(post.path)}`;
  const images = [post.seo.share_image?.url, post.cover?.url, post.details?.kind === "infographic" ? post.details.download?.url : undefined].filter(
    (u, i, all): u is string => !!u && all.indexOf(u) === i,
  );
  const organization = { "@type": "NewsMediaOrganization", "@id": `${base}/#organization`, name: site?.name ?? "رَقَم", url: `${base}/`, logo: { "@type": "ImageObject", url: site?.logo?.url ?? `${base}/brand/logo-512.png` } };
  const section = crumbs.length > 1 ? crumbs[1].name : undefined;
  // The video's own object joins the page's graph, which carries the context once.
  const video = Object.fromEntries(Object.entries(post.video?.structured_data ?? {}).filter(([key]) => key !== "@context"));

  const article: Record<string, unknown> = {
    "@type": post.kind.schema_type || "Article",
    "@id": `${url}#article`,
    headline: post.title.length > 110 ? `${post.title.slice(0, 109)}…` : post.title,
    ...(post.seo.description || post.excerpt ? { description: post.seo.description || post.excerpt } : {}),
    ...(images.length ? { image: images } : {}),
    datePublished: post.published_at,
    dateModified: post.updated_at,
    author: post.authors.length
      ? post.authors.map((a) => ({ "@type": "Person", name: a.name, url: `${base}${encodeURI(`/author/${a.slug}`)}` }))
      : [{ "@type": "Organization", name: "فريق رَقَم", url: `${base}/` }],
    publisher: { "@id": organization["@id"] },
    mainEntityOfPage: url,
    url,
    inLanguage: "ar",
    isAccessibleForFree: true,
    ...(section ? { articleSection: section } : {}),
    ...(post.tags.length ? { keywords: post.tags.map((t) => t.name).join("، ") } : {}),
    ...(post.video?.structured_data ? { video: { "@id": `${url}#video` } } : {}),
  };

  const breadcrumb = {
    "@type": "BreadcrumbList",
    "@id": `${url}#breadcrumb`,
    itemListElement: crumbs.map((c, i) => ({ "@type": "ListItem", position: i + 1, name: c.name, item: `${base}${encodeURI(c.path)}` })),
  };

  return {
    "@context": "https://schema.org",
    "@graph": [article, breadcrumb, organization, ...(post.video?.structured_data ? [{ ...video, "@id": `${url}#video` }] : [])],
  };
}
