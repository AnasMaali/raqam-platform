import Link from "next/link";
import { connection } from "next/server";

import type { Crumb } from "@/components/article/ArticleParts";
import { PageBody, PageHeader, PageJsonLd, PageUnavailable, noSeo, pageMetadata } from "@/components/pages/PageParts";
import { getFaqs, getPage } from "@/lib/server/pages";

/*
 * The questions page (decision 28, page inventory: /faq): the page's text from the panel, then the questions in the
 * admin's order, each answer in the page itself (open by a press, read by search engines and screen readers alike),
 * and FAQPage structured data with every question and answer. The same questions feed the chatbot (18.4).
 */
export const instant = false;

const path = "/faq";
const crumbs: Crumb[] = [
  { name: "الرئيسية", path: "/" },
  { name: "الأسئلة الشائعة", path },
];

async function load() {
  try {
    const [faqs, page] = await Promise.all([getFaqs(), getPage("faq")]);

    return { faqs, page };
  } catch (error) {
    console.error(`[site] the questions are unavailable: ${(error as Error).message}`);

    return null;
  }
}

export async function generateMetadata() {
  const loaded = await load();
  const page = loaded?.page ?? { title: "الأسئلة الشائعة", seo: noSeo };
  const first = loaded?.faqs.slice(0, 3).map((f) => f.question) ?? [];

  return pageMetadata(page, path, first.length ? `أجوبة رَقَم عن أكثر ما يُسأل: ${first.join(" ")}` : undefined);
}

export default async function FaqPage() {
  const loaded = await load();
  if (!loaded) {
    await connection();

    return <PageUnavailable what="الأسئلة الشائعة" />;
  }
  const { faqs, page } = loaded;
  const title = page?.title ?? "الأسئلة الشائعة";

  return (
    <>
      <div className="mx-auto max-w-page px-4 pt-2 pb-16 sm:px-6 lg:px-8">
        <PageHeader title={title} crumbs={crumbs} />
        {page && <PageBody html={page.body_html} className="mt-6" />}
        {faqs.length > 0 ? (
          <div className="mt-8 max-w-text divide-y divide-line border-y border-line">
            {faqs.map((faq) => (
              <details key={faq.id} id={`q-${faq.id}`} className="group">
                <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 py-4 font-bold hover:text-brand [&::-webkit-details-marker]:hidden">
                  <h2 className="text-body font-bold">{faq.question}</h2>
                  <span aria-hidden="true" className="shrink-0 text-heading leading-none text-brand group-open:hidden">
                    +
                  </span>
                  <span aria-hidden="true" className="hidden shrink-0 text-heading leading-none text-brand group-open:inline">
                    −
                  </span>
                </summary>
                <p className="pb-5 whitespace-pre-line text-muted">{faq.answer}</p>
              </details>
            ))}
          </div>
        ) : (
          <p className="mt-8 text-lead text-muted">لا أسئلة منشورة بعد.</p>
        )}
        <p className="mt-10 max-w-text">
          لم تجد جوابك؟{" "}
          <Link href="/contact" className="inline-flex min-h-11 items-center font-bold text-brand underline">
            اكتب لنا
          </Link>
        </p>
      </div>
      <PageJsonLd
        page={{ title, seo: page?.seo ?? noSeo, system_key: "faq", updated_at: page?.updated_at ?? null }}
        path={path}
        crumbs={crumbs}
        extra={{
          mainEntity: faqs.map((f) => ({ "@type": "Question", name: f.question, acceptedAnswer: { "@type": "Answer", text: f.answer } })),
        }}
      />
    </>
  );
}
