import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { ContactForm } from "@/components/contact/ContactForm";
import { PageBody, PageHeader, PageJsonLd, pageMetadata } from "@/components/pages/PageParts";
import { Icon } from "@/components/ui/Icon";
import { getPage } from "@/lib/server/pages";
import { getSite } from "@/lib/server/site-data";
import type { SitePage } from "@/lib/types";

/*
 * «اتصل بنا» (page inventory: /contact): the page's text, the platform's contact details (phone, WhatsApp, e-mail,
 * address, the map link, the social accounts from /site), and the form, sent through the site's own route.
 */
export const instant = false;

const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "اتصل بنا", path: "/contact" },
];
const blank: SitePage["seo"] = { title: null, description: null, canonical_url: null, noindex: false, share_image: null };

async function loadPage() {
  try {
    return await getPage("contact");
  } catch (error) {
    console.error(`[site] the contact page's text is unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const page = await loadPage();

  return pageMetadata(page ?? { title: "اتصل بنا", seo: blank }, "/contact", "تواصل مع فريق رَقَم: الهاتف وواتساب والبريد الإلكتروني ونموذج المراسلة.");
}

const linkClass = "inline-flex min-h-11 items-center gap-2 hover:text-brand";

export default async function ContactPage() {
  const [page, site] = await Promise.all([loadPage(), getSite()]);
  if (!site) await connection();
  const contact = site?.contact;
  const siteKey = process.env.TURNSTILE_SITE_KEY?.trim();
  if (!siteKey) console.error("[site] the contact form is left out: TURNSTILE_SITE_KEY is not set");
  const title = page?.title ?? "اتصل بنا";

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={title} crumbs={crumbs} />
        {page && <PageBody html={page.body_html} className="mt-6" />}

        <div className="mt-10 grid gap-x-12 gap-y-10 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
          <section aria-labelledby="form-title">
            <h2 id="form-title" className="text-heading font-bold">
              راسلنا
            </h2>
            <div className="mt-5">
              {siteKey ? <ContactForm siteKey={siteKey} /> : <p className="text-muted">النموذج غير متاح الآن. تواصل معنا بالهاتف أو البريد.</p>}
            </div>
          </section>

          {contact && (
            <section aria-labelledby="details-title" className="border-t-2 border-brand pt-3">
              <h2 id="details-title" className="text-subheading font-bold">
                بيانات التواصل
              </h2>
              <ul className="mt-2 flex flex-col">
                {contact.phone && (
                  <li>
                    <a href={`tel:${contact.phone}`} className={linkClass}>
                      <Icon name="phone" className="text-brand" />
                      <span className="sr-only">الهاتف: </span>
                      <span dir="ltr">{contact.phone}</span>
                    </a>
                  </li>
                )}
                {contact.whatsapp_url && (
                  <li>
                    <a href={contact.whatsapp_url} target="_blank" rel="noopener" className={linkClass}>
                      <Icon name="chat" className="text-brand" />
                      واتساب<span className="sr-only"> (يفتح في نافذة جديدة)</span>
                    </a>
                  </li>
                )}
                {contact.email && (
                  <li>
                    <a href={`mailto:${contact.email}`} className={linkClass}>
                      <Icon name="mail" className="text-brand" />
                      <span className="sr-only">البريد الإلكتروني: </span>
                      <span dir="ltr">{contact.email}</span>
                    </a>
                  </li>
                )}
                {contact.address && (
                  <li className="flex min-h-11 items-center gap-2">
                    <Icon name="pin" className="text-brand" />
                    <span className="sr-only">العنوان: </span>
                    {contact.address}
                  </li>
                )}
                {contact.map_url && (
                  <li>
                    <a href={contact.map_url} target="_blank" rel="noopener" className={`${linkClass} font-medium text-brand`}>
                      افتح الموقع على الخريطة<span className="sr-only"> (يفتح في نافذة جديدة)</span>
                    </a>
                  </li>
                )}
              </ul>
              {site.social.length > 0 && (
                <>
                  <h3 className="mt-6 font-bold">تابعنا</h3>
                  <ul className="mt-1 flex flex-wrap gap-x-4">
                    {site.social.map((s) => (
                      <li key={s.url}>
                        <a href={s.url} target="_blank" rel="noopener me" className="inline-flex min-h-11 items-center hover:text-brand">
                          {s.name}
                        </a>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </section>
          )}
        </div>
      </div>
      <PageJsonLd
        page={{ title, seo: page?.seo ?? blank, system_key: "contact", updated_at: page?.updated_at }}
        path="/contact"
        crumbs={crumbs}
        extra={{
          mainEntity: {
            "@type": "Organization",
            name: site?.name ?? "رَقَم",
            ...(contact?.phone ? { telephone: contact.phone } : {}),
            ...(contact?.email ? { email: contact.email } : {}),
            ...(contact?.address ? { address: contact.address } : {}),
          },
        }}
      />
    </>
  );
}
