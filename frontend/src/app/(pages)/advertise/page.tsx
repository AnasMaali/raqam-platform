import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageBody, PageHeader, PageJsonLd, PageUnavailable, describe, noSeo, pageMetadata } from "@/components/pages/PageParts";
import { ContactButtons } from "@/components/services/ContactButtons";
import { ServiceCard } from "@/components/services/ServiceParts";
import { getAdvertise } from "@/lib/server/pages";
import { serviceNode } from "@/lib/server/service-data";
import type { AdSize } from "@/lib/types";

/*
 * «أعلن معنا» (13.14, page inventory: /advertise): the page's text, the ad places that are on with their sizes on a
 * large screen and on a phone, the ad packages with their prices (services of the «إعلانات» group), and the contact
 * buttons. There is no booking online in V1.
 */
export const instant = false;

const path = "/advertise";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "أعلن معنا", path },
];

async function load() {
  try {
    return await getAdvertise();
  } catch (error) {
    console.error(`[site] the advertising page is unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const board = await load();
  const page = board?.page ?? { title: "أعلن معنا", seo: noSeo };
  const places = board?.placements.map((p) => p.name) ?? [];

  return pageMetadata(page, path, places.length ? `أعلن في رَقَم: ${places.join("، ")}، بمقاساتها على الشاشات الكبيرة والجوال، مع تقرير بالمشاهدات والنقرات. تواصل معنا.` : describe(board?.page?.body_html));
}

/** A size in pixels, beside a box of its shape on one scale for every place (970 px is 12rem), so they compare. */
function Size({ label, size }: { label: string; size: AdSize | null }) {
  if (!size) return null;

  return (
    <div>
      <p className="text-small">
        <span className="text-muted">{label}: </span>
        <span dir="ltr" className="figures font-bold whitespace-nowrap">{`${size.width} × ${size.height}`}</span>{" "}
        <span className="text-muted">بكسل</span>
      </p>
      <span
        aria-hidden="true"
        className="mt-2 block max-w-full border border-dashed border-line-strong bg-tint"
        style={{ width: `${Math.round((size.width / 970) * 1200) / 100}rem`, aspectRatio: `${size.width} / ${size.height}` }}
      />
    </div>
  );
}

export default async function AdvertisePage() {
  const board = await load();
  if (!board) {
    await connection();

    return <PageUnavailable what="صفحة الإعلان" />;
  }
  const title = board.page?.title ?? "أعلن معنا";

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={title} crumbs={crumbs} />
        {board.page && <PageBody html={board.page.body_html} className="mt-6" />}
        <div className="mt-6">
          <ContactButtons links={board.contact} topic="إعلان في رَقَم" />
        </div>

        {board.placements.length > 0 && (
          <section aria-labelledby="ad-places" className="mt-12">
            <h2 id="ad-places" className="text-heading font-bold">
              أماكن الإعلان ومقاساتها
            </h2>
            <ul className="mt-6 grid gap-6 sm:grid-cols-2">
              {board.placements.map((place) => (
                <li key={place.code} className="border-t border-line pt-4">
                  <h3 className="font-bold">{place.name}</h3>
                  <div className="mt-3 space-y-4">
                    <Size label="على الشاشات الكبيرة" size={place.sizes.desktop} />
                    <Size label="على الجوال" size={place.sizes.mobile} />
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )}

        {board.packages.length > 0 && (
          <section aria-labelledby="ad-packages" className="mt-12">
            <h2 id="ad-packages" className="text-heading font-bold">
              الباقات
            </h2>
            <ul className="mt-6 grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
              {board.packages.map((service) => (
                <li key={service.id}>
                  <ServiceCard service={service} bookingOn={board.booking.enabled} />
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
      <PageJsonLd
        page={{ title, seo: board.page?.seo ?? noSeo }}
        path={path}
        crumbs={crumbs}
        extra={
          board.packages.length
            ? { mainEntity: { "@type": "ItemList", numberOfItems: board.packages.length, itemListElement: board.packages.map((s, i) => ({ "@type": "ListItem", position: i + 1, item: serviceNode(s) })) } }
            : {}
        }
      />
    </>
  );
}
