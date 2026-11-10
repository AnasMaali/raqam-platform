import "server-only";

import type { ServiceDetail, ServiceSummary } from "@/lib/types";

import { serverEnv } from "./env";

/**
 * A service as schema.org reads it: a Service from the platform, with its address and its offer. A fixed price is an
 * Offer's price; «يبدأ من» is a minimum price; a price on request has no offer. A special offer says when it ends.
 */
export function serviceNode(service: ServiceSummary | ServiceDetail) {
  const base = serverEnv().siteUrl.origin;
  const url = `${base}${encodeURI(service.path)}`;
  const { price } = service;
  const offer =
    price.mode === "on_request" || !price.amount || !price.currency
      ? null
      : {
          "@type": "Offer",
          priceCurrency: price.currency,
          availability: "https://schema.org/InStock",
          url,
          ...(price.mode === "fixed"
            ? { price: price.amount }
            : { priceSpecification: { "@type": "PriceSpecification", minPrice: price.amount, priceCurrency: price.currency } }),
          ...(price.offer?.ends_at ? { validThrough: price.offer.ends_at } : {}),
        };
  const fromPlatform = "structured_data" in service && service.structured_data ? Object.fromEntries(Object.entries(service.structured_data).filter(([k]) => k !== "@context" && k !== "offers")) : {};

  return {
    "@type": "Service",
    ...fromPlatform,
    "@id": `${url}#service`,
    name: service.name,
    ...(service.summary ? { description: service.summary } : {}),
    url,
    provider: { "@id": `${base}/#organization` },
    ...(offer ? { offers: offer } : {}),
  };
}
