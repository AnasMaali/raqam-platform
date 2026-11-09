import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageBody, PageHeader, PageJsonLd, describe, pageMetadata } from "@/components/pages/PageParts";
import { ServiceCard } from "@/components/services/ServiceParts";
import { getServices } from "@/lib/server/pages";
import { serviceNode } from "@/lib/server/service-data";

/*
 * The services and their prices (page inventory: /services, 14.6): the page's text, then the services in their groups,
 * each with its price, a special offer and its end, and «تواصل للحجز». This page is how visitors book in V1; the
 * button for booking online appears only while the platform's booking switch is on.
 */
export const instant = false;

const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "الخدمات", path: "/services" },
];

async function load() {
  try {
    return await getServices();
  } catch (error) {
    console.error(`[site] the services are unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const board = await load();
  const page = board?.page ?? { title: "خدماتنا والأسعار", seo: { title: null, description: null, canonical_url: null, noindex: false, share_image: null } };
  const names = board?.groups.flatMap((g) => g.services.map((s) => s.name)).slice(0, 4) ?? [];

  return pageMetadata(page, "/services", names.length ? `خدمات رَقَم الإعلامية وأسعارها: ${names.join("، ")}، وغيرها. تواصل معنا للحجز.` : describe(board?.page?.body_html));
}

export default async function ServicesPage() {
  const board = await load();
  if (!board) {
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الخدمات الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const title = board.page?.title ?? "خدماتنا والأسعار";
  const services = board.groups.flatMap((g) => g.services);

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={title} crumbs={crumbs} />
        {board.page && <PageBody html={board.page.body_html} className="mt-6" />}
        {board.meta.currency_note && <p className="mt-4 text-small text-muted">{board.meta.currency_note}</p>}
        {board.groups
          .filter((g) => g.services.length > 0)
          .map((group, g) => (
            <section key={group.id} aria-labelledby={`group-${group.id}`} className="mt-12">
              <h2 id={`group-${group.id}`} className="text-heading font-bold">
                {group.name}
              </h2>
              <ul className="mt-6 grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                {group.services.map((service, i) => (
                  <li key={service.id}>
                    {/* The first picture is in the first screen on a phone: it loads at once. */}
                    <ServiceCard service={service} bookingOn={board.meta.booking.enabled} priority={g === 0 && i === 0} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
        {services.length === 0 && <p className="mt-8 text-lead text-muted">لا خدمات معروضة الآن. تواصل معنا لأي استفسار.</p>}
      </div>
      <PageJsonLd
        page={{ title, seo: board.page?.seo ?? { title: null, description: null, canonical_url: null, noindex: false, share_image: null } }}
        path="/services"
        crumbs={crumbs}
        extra={{ mainEntity: { "@type": "ItemList", numberOfItems: services.length, itemListElement: services.map((s, i) => ({ "@type": "ListItem", position: i + 1, item: serviceNode(s) })) } }}
      />
    </>
  );
}
