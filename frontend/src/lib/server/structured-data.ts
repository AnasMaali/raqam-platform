import "server-only";

import type { Site } from "@/lib/types";

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
