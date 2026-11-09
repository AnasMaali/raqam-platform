import { notFound } from "next/navigation";
import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { CompactCard } from "@/components/cards/PostCard";
import { PageBody, PageHeader, PageJsonLd, pageMetadata } from "@/components/pages/PageParts";
import { ContactButtons } from "@/components/services/ContactButtons";
import { BookingLink, PriceTag } from "@/components/services/ServiceParts";
import { Pic } from "@/components/ui/Pic";
import { getServices, getService } from "@/lib/server/pages";
import { serviceNode } from "@/lib/server/service-data";

/*
 * One service (page inventory: /services/{slug}, 14.7, 14.8): the details, what it includes, its own questions,
 * published work of the kind, and the contact buttons (WhatsApp with a ready message, a call, an e-mail with a ready
 * subject), each press counted through the site's server. Service and Offer in the structured data (the service's own questions stay on the page: FAQPage is the /faq page's). An unknown or
 * hidden service is a 404 before the page streams (src/proxy.ts).
 */
export const instant = false;

export async function generateStaticParams() {
  const board = await getServices();
  const slugs = board.groups.flatMap((g) => g.services.map((s) => s.slug));

  // At least one: a route built ahead needs a real parameter (an unknown one builds as not found).
  return (slugs.length ? slugs : ["-"]).map((slug) => ({ slug }));
}

type Params = PageProps<"/services/[slug]">["params"];

async function load(params: Params) {
  let slug: string;
  try {
    slug = decodeURIComponent((await params).slug);
  } catch {
    return { status: "missing" as const };
  }

  try {
    const found = await getService(slug);

    return found ? { status: "ok" as const, ...found } : { status: "missing" as const };
  } catch (error) {
    console.error(`[site] service ${slug} is unavailable: ${(error as Error).message}`);

    return { status: "unavailable" as const };
  }
}

export async function generateMetadata({ params }: PageProps<"/services/[slug]">) {
  const loaded = await load(params);
  if (loaded.status !== "ok") return {};
  const { service } = loaded;

  return pageMetadata({ title: service.name, seo: service.seo }, service.path, [service.summary, service.price.text].filter(Boolean).join(" "));
}

export default async function ServicePage({ params }: PageProps<"/services/[slug]">) {
  const loaded = await load(params);
  if (loaded.status === "missing") notFound();
  if (loaded.status === "unavailable") {
    await connection();

    return (
      <div className="mx-auto max-w-page px-4 py-16 sm:px-6 lg:px-8">
        <h1 className="text-title font-bold">تعذّر تحميل الخدمة الآن</h1>
        <p className="mt-4 max-w-text text-lead text-muted">حدّث الصفحة بعد قليل.</p>
      </div>
    );
  }
  const { service, meta } = loaded;
  const crumbs: Crumb[] = [
    { name: "الرئيسية", path: "/" },
    { name: "الخدمات", path: "/services" },
    { name: service.name, path: service.path },
  ];

  return (
    <>
      <article className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={service.name} crumbs={crumbs}>
          {service.summary && <p className="mt-3 max-w-text text-lead text-muted">{service.summary}</p>}
        </PageHeader>

        <div className="mt-8 grid gap-x-12 gap-y-10 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
          <div className="flex flex-col gap-10">
            <Pic media={service.cover} sizes="(min-width: 64rem) 60vw, 100vw" ratio="16/9" priority />
            {service.description_html && <PageBody html={service.description_html} />}
            {service.deliverables.length > 0 && (
              <section aria-labelledby="deliverables">
                <h2 id="deliverables" className="text-heading font-bold">
                  ماذا تشمل الخدمة
                </h2>
                <ul className="mt-4 flex max-w-text list-disc flex-col gap-2 ps-6">
                  {service.deliverables.map((d) => (
                    <li key={d}>{d}</li>
                  ))}
                </ul>
              </section>
            )}
            {service.faqs.length > 0 && (
              <section aria-labelledby="service-faq">
                <h2 id="service-faq" className="text-heading font-bold">
                  أسئلة عن الخدمة
                </h2>
                <div className="mt-4 max-w-text">
                  {service.faqs.map((faq) => (
                    <details key={faq.question} className="group border-b border-line">
                      <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 py-2 font-medium hover:text-brand [&::-webkit-details-marker]:hidden">
                        {faq.question}
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true" className="shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none">
                          <path d="M6 9.5l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                      </summary>
                      <p className="pb-4 text-muted">{faq.answer}</p>
                    </details>
                  ))}
                </div>
              </section>
            )}
          </div>

          <aside id="contact" aria-labelledby="book-title" className="scroll-mt-24 border-t-2 border-brand pt-3 lg:sticky lg:top-24">
            <h2 id="book-title" className="text-subheading font-bold">
              السعر والحجز
            </h2>
            <div className="mt-3">
              <PriceTag price={service.price} large />
            </div>
            {meta.currency_note && <p className="mt-2 text-small text-muted">{meta.currency_note}</p>}
            <p className="mt-4 text-small">للحجز، تواصل معنا وسنرتّب التفاصيل:</p>
            <div className="mt-3">
              <ContactButtons slug={service.slug} links={service.contact} topic={service.name} />
            </div>
            <div className="mt-3">
              <BookingLink service={service} on={meta.booking.enabled} />
            </div>
          </aside>
        </div>

        {service.examples.length > 0 && (
          <section aria-labelledby="examples" className="mt-16">
            <h2 id="examples" className="text-heading font-bold">
              أعمال منشورة
            </h2>
            <ul className="mt-6 grid gap-x-6 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
              {service.examples.slice(0, 6).map((post) => (
                <li key={post.id}>
                  <CompactCard post={post} />
                </li>
              ))}
            </ul>
          </section>
        )}
      </article>
      <PageJsonLd
        page={{ title: service.name, seo: service.seo }}
        path={service.path}
        crumbs={crumbs}
        extra={{ mainEntity: { "@id": `${serviceNode(service)["@id"]}` } }}
        more={[serviceNode(service)]}
      />
    </>
  );
}
